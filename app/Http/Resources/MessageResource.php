<?php

namespace App\Http\Resources;

use App\Enums\Connection\Channel;
use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Message;
use App\Services\Media\MediaRetention;
use App\Services\Message\VCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public bool $withoutAttachmentUrl = false;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_type' => $this->sender_type,
            'message_type' => $this->message_type,
            'body' => $this->body,
            'attachment_url' => $this->when(!$this->withoutAttachmentUrl, fn() =>
                $this->resolveAttachmentUrl($this->attachment)
            ),
            // Only set while the file is still being fetched off the channel
            // (or after that gave up) — see App\Jobs\DownloadInboundMedia —
            // and once retention deleted it. The chat panel reads it to draw a
            // placeholder instead of a bubble pointing at nothing.
            'attachment_status' => $this->resolveAttachmentStatus(),
            'replied_message' => $this->when($this->repliedMessage, fn() => [
                'id' => $this->repliedMessage->id,
                'sender_type' => $this->repliedMessage->sender_type,
                'message_type' => $this->repliedMessage->message_type,
                'body' => $this->repliedMessage->body,
                'attachment_url' => !$this->withoutAttachmentUrl
                    // The quoted message has its own age, so its own purge date:
                    // a reply written today does not extend the life of the
                    // photo it quotes.
                    ? $this->resolveAttachmentUrl($this->repliedMessage->attachment, $this->repliedMessage)
                    : null,
            ]),
            // The chat panel lets a reader open a reaction to see who left it
            // and when, so each one is emitted individually rather than
            // pre-aggregated by emoji — the grouping is a presentation choice.
            // `contact` is only ever set in a group, where several members can
            // react to the same message; in a private chat `sender_type`
            // already names the only two possible reactors.
            'reactions' => $this->reactions->map(fn ($reaction) => [
                'id' => $reaction->id,
                'emoji' => $reaction->emoji,
                'sender_type' => $reaction->sender_type,
                'created_at' => $reaction->created_at->timestamp,
                'contact' => $reaction->contact ? [
                    'id' => $reaction->contact->id,
                    'name' => $reaction->contact->name,
                    'photo_profile_url' => $reaction->contact->photo_profile_url,
                ] : null,
            ]),
            'sent_at' => $this->sent_at,
            'delivery_at' => $this->delivery_at,
            'read_at' => $this->read_at,
            'edited_at' => $this->edited_at,
            'unsend_at' => $this->unsend_at,
            'starred_at' => $this->starred_at,
            'sender' => $this->getSenderInfo(),
            'meta' => $this->getProcessedMeta(),
            'created_at' => $this->created_at->timestamp,
            'updated_at' => $this->updated_at->timestamp,
        ];
    }

    /**
     * Resolve an attachment reference into a URL the frontend can render.
     * External attachments (media sent by URL) are stored as absolute URLs and
     * returned as-is; local storage paths get a signed temporary URL.
     *
     * The signature expires on the file's purge date rather than at some fixed
     * distance from now: the SPA caches this string in IndexedDB and never
     * re-fetches it, so a URL that outlives its file would sit there pointing
     * at a 403 with nothing to say why. See App\Services\Media\MediaRetention.
     *
     * $owner names the message the path belongs to — normally this one, but a
     * quoted message when resolving `replied_message`.
     */
    private function resolveAttachmentUrl(?string $attachment, ?Message $owner = null): ?string
    {
        return MediaRetention::signedUrl($attachment, $owner ?? $this->resource, $this->conversation);
    }

    /**
     * The stored status, or `expired` for a file whose retention window has
     * closed but which the sweep has not reached yet — the row says nothing
     * for up to an hour, while the URL above already refuses to resolve.
     */
    private function resolveAttachmentStatus(): ?AttachmentStatus
    {
        if ($this->attachment_status !== null) {
            return $this->attachment_status;
        }

        if ($this->attachment
            && !MediaRetention::isExternal($this->attachment)
            && MediaRetention::isExpired($this->resource, $this->conversation)) {
            return AttachmentStatus::Expired;
        }

        return null;
    }

    /**
     * Resolve who/what sent this message.
     *
     * source values:
     *   - human       → sent by a logged-in agent via the UI/API
     *   - ai_flow     → sent by an AI Agent node inside a flow
     *   - static_flow → sent by a non-AI flow node (Message/Response/validation)
     *   - external    → outgoing but no tracked sender (e.g. V1 SendMessage API or legacy)
     *   - contact     → incoming message with a per-message sender (group conversations)
     */
    private function getSenderInfo(): ?array
    {
        if ($this->sender_type !== SenderType::Outgoing) {
            // Incoming: only group messages track a per-message sender; in a
            // private conversation the sender is the conversation's contact.
            if ($this->contact_id) {
                $contact = $this->contact;
                return [
                    'source' => 'contact',
                    'contact' => $contact ? [
                        'id' => $contact->id,
                        'name' => $contact->name,
                        'username' => $contact->username,
                        // The chat thread shows each member's avatar beside
                        // their first message in a run, so the sender carries
                        // its own photo — the conversation's contact is the
                        // group, not the person who wrote this.
                        'photo_profile_url' => $contact->photo_profile_url,
                    ] : null,
                ];
            }

            return null;
        }

        if ($this->sent_by_user_id) {
            $user = $this->sentByUser;
            return [
                'source' => 'human',
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                ] : null,
            ];
        }

        if ($this->sent_by_ai_hub_agent_id) {
            $agent = $this->sentByAiHubAgent;
            $flow = $this->sentByFlow;
            return [
                'source' => 'ai_flow',
                'flow' => $flow ? [
                    'id' => $flow->id,
                    'name' => $flow->name,
                ] : null,
                'ai_hub_agent' => $agent ? [
                    'id' => $agent->id,
                    'name' => $agent->name,
                ] : null,
            ];
        }

        if ($this->sent_by_flow_id) {
            $flow = $this->sentByFlow;
            return [
                'source' => 'static_flow',
                'flow' => $flow ? [
                    'id' => $flow->id,
                    'name' => $flow->name,
                ] : null,
            ];
        }

        return ['source' => 'external'];
    }

    /**
     * Get processed meta based on channel and message type
     */
    private function getProcessedMeta(): ?array
    {
        // A system note belongs to the platform, not to a channel: its code +
        // params travel the same way on every connection.
        if ($this->message_type === MessageType::Info) {
            $info = $this->meta['info'] ?? null;

            return is_array($info) ? ['info' => $info] : null;
        }

        $channel = $this->conversation->connection->channel ?? null;

        if (!$channel) {
            return null;
        }

        return match($channel) {
            Channel::WhatsappApiway => $this->getWhatsappApiwayMeta(),
            Channel::WhatsappOfficial => $this->getWhatsappOfficialMeta(),
            Channel::Instagram => $this->getInstagramMeta(),
            Channel::Telegram => null,         // TODO: implement when needed
            Channel::Email => $this->getEmailMeta(),
            default => null,
        };
    }

    /**
     * A post or reel shared into the DM. The rest of an Instagram webhook
     * never reaches the SPA, so this is the curated copy the handler wrote —
     * the link the bubble offers, plus the caption of a post we deliberately
     * did not mirror. See InstagramHandler::shareData().
     */
    private function getInstagramMeta(): ?array
    {
        if ($this->message_type !== MessageType::InstagramShare) {
            return null;
        }

        $share = $this->meta['instagram_share'] ?? null;

        return is_array($share) ? ['instagram_share' => $share] : null;
    }

    private function getEmailMeta(): ?array
    {
        $email = $this->meta['email'] ?? null;

        if (!is_array($email)) {
            return null;
        }

        // Turn each attachment's private storage path into a signed, downloadable
        // URL. Skipped for the conversation-list preview (withoutAttachmentUrl) to
        // avoid signing URLs for rows the user never opens.
        if (!$this->withoutAttachmentUrl && !empty($email['attachments']) && is_array($email['attachments'])) {
            $email['attachments'] = array_map(function ($attachment) {
                $path = $attachment['path'] ?? null;
                $attachment['url'] = $path ? $this->resolveAttachmentUrl($path) : null;
                return $attachment;
            }, $email['attachments']);
        }

        // The HTML body itself stays on disk (too large for broadcasts and
        // IndexedDB); the SPA fetches it on demand when this flag is set.
        $email['has_html'] = !empty($email['html_path']);
        unset($email['html_path']);

        return ['email' => $email];
    }

    /**
     * Get processed meta for WhatsApp Official (Cloud API) messages.
     * Surfaces the interactive structure so the UI can render buttons/lists
     * (outbound) and read which option the customer selected (inbound).
     */
    private function getWhatsappOfficialMeta(): ?array
    {
        return match($this->message_type) {
            MessageType::Interactive => $this->getWhatsappOfficialInteractiveData(),
            MessageType::Contact => $this->getWhatsappOfficialContactData(),
            default => null,
        };
    }

    private function getWhatsappOfficialInteractiveData(): ?array
    {
        // Outbound: the sent payload is stored under meta.interactive.
        $outbound = $this->meta['interactive'] ?? null;
        // Inbound: the customer's reply lives in the raw webhook entry.
        $inboundReply = $this->meta['changes'][0]['value']['messages'][0]['interactive'] ?? null;

        $interactive = $outbound ?? $inboundReply;

        return $interactive ? ['interactive' => $interactive] : null;
    }

    /**
     * Shared contact cards (vCard), parsed into something a bubble can render.
     *
     * Read off the stored webhook rather than kept in a column of its own, so
     * a later fix to the parser applies to messages already in the table.
     */
    private function getWhatsappOfficialContactData(): ?array
    {
        $contacts = $this->meta['changes'][0]['value']['messages'][0]['contacts'] ?? null;

        if (! is_array($contacts)) {
            return null;
        }

        $cards = VCard::cardsFromCloudApi($contacts);

        return $cards === [] ? null : ['contacts' => $cards];
    }

    /**
     * Get processed meta for WhatsApp API Way messages
     */
    private function getWhatsappApiwayMeta(): ?array
    {
        return match($this->message_type) {
            MessageType::Location => $this->getWhatsappApiwayLocationData(),
            MessageType::Contact => $this->getWhatsappApiwayContactData(),
            default => null,
        };
    }

    /**
     * Shared contact cards (vCard), parsed into something a bubble can render.
     *
     * The webhook is stored whole, so this is a read of what already arrived —
     * no second call to the channel, and re-parsing an old row picks up any
     * later fix to the parser.
     */
    private function getWhatsappApiwayContactData(): ?array
    {
        $cards = VCard::cardsFromWhatsmeow($this->apiwayMessageNode());

        return $cards === [] ? null : ['contacts' => $cards];
    }

    /**
     * The `Message` node of a stored API Way webhook.
     *
     * Two payload shapes are in the table: `Message` is what the whatsmeow
     * webhook has sent since the channel moved to the native format, and
     * `msgContent` is what rows written before that carry. Reading both keeps
     * history rendering instead of turning into empty bubbles.
     */
    private function apiwayMessageNode(): array
    {
        $node = $this->meta['Message'] ?? $this->meta['msgContent'] ?? null;

        return is_array($node) ? $node : [];
    }

    /**
     * Extract location data from WhatsApp API Way meta
     */
    private function getWhatsappApiwayLocationData(): ?array
    {
        $location = $this->apiwayMessageNode()['locationMessage'] ?? null;

        if (!$location) {
            return null;
        }

        $latitude = $location['degreesLatitude'] ?? null;
        $longitude = $location['degreesLongitude'] ?? null;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'google_maps_url' => "https://www.google.com/maps?q={$latitude},{$longitude}",
            ],
        ];
    }
}
