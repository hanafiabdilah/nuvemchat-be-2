<?php

namespace App\Http\Resources;

use App\Enums\Connection\Channel;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
                $this->attachment ? Storage::disk('local')->temporaryUrl($this->attachment, Carbon::now()->addMonths(6)) : null
            ),
            'replied_message' => $this->when($this->repliedMessage, fn() => [
                'id' => $this->repliedMessage->id,
                'sender_type' => $this->repliedMessage->sender_type,
                'message_type' => $this->repliedMessage->message_type,
                'body' => $this->repliedMessage->body,
                'attachment_url' => $this->repliedMessage->attachment && !$this->withoutAttachmentUrl
                    ? Storage::disk('local')->temporaryUrl($this->repliedMessage->attachment, Carbon::now()->addMonths(6))
                    : null,
            ]),
            'reactions' => $this->when($this->reactions, fn() =>
                $this->reactions->map(fn($reaction) => [
                    'emoji' => $reaction->emoji,
                    'sender_type' => $reaction->sender_type,
                    'created_at' => $reaction->created_at->timestamp,
                ])
            ),
            'sent_at' => $this->sent_at,
            'delivery_at' => $this->delivery_at,
            'read_at' => $this->read_at,
            'edited_at' => $this->edited_at,
            'unsend_at' => $this->unsend_at,
            'sender' => $this->getSenderInfo(),
            'meta' => $this->getProcessedMeta(),
            'created_at' => $this->created_at->timestamp,
            'updated_at' => $this->updated_at->timestamp,
        ];
    }

    /**
     * Resolve who/what sent this message.
     * Outgoing only: returns null for incoming messages.
     *
     * source values:
     *   - human       → sent by a logged-in agent via the UI/API
     *   - ai_flow     → sent by an AI Agent node inside a flow
     *   - static_flow → sent by a non-AI flow node (Message/Response/validation)
     *   - external    → outgoing but no tracked sender (e.g. V1 SendMessage API or legacy)
     */
    private function getSenderInfo(): ?array
    {
        if ($this->sender_type !== SenderType::Outgoing) {
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
        $channel = $this->conversation->connection->channel ?? null;

        if (!$channel) {
            return null;
        }

        return match($channel) {
            Channel::WhatsappWApi => $this->getWhatsappWApiMeta(),
            Channel::WhatsappOfficial => null, // TODO: implement when needed
            Channel::Instagram => null,        // TODO: implement when needed
            Channel::Telegram => null,         // TODO: implement when needed
            default => null,
        };
    }

    /**
     * Get processed meta for WhatsApp W-API messages
     */
    private function getWhatsappWApiMeta(): ?array
    {
        return match($this->message_type) {
            MessageType::Location => $this->getWhatsappWApiLocationData(),
            default => null,
        };
    }

    /**
     * Extract location data from WhatsApp W-API meta
     */
    private function getWhatsappWApiLocationData(): ?array
    {
        $location = $this->meta['msgContent']['locationMessage'] ?? null;

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
