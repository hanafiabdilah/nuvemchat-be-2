<?php

namespace App\Services\Broadcast;

use App\Enums\Broadcast\ContentType;
use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversation\OutboundConversationResolver;
use App\Services\Message\Handlers\EmailHandler;
use App\Services\Message\MessageService;
use App\Services\Messaging\MessagingWindow;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a campaign to exactly one recipient, and opens the conversation it
 * belongs in on the way.
 *
 * The conversation is the point, not a side effect: a campaign that only fired
 * messages at an API would leave the customer's reply with nowhere to land. So
 * every successful send ends with a real thread in the inbox — assigned,
 * tagged, and announced on the tenant channel — which is why the dashboard sees
 * threads appear one by one as a campaign runs rather than in one lump at the
 * end.
 *
 * Nothing in here is allowed to throw. A recipient that cannot be reached is a
 * row marked failed with the platform's own words in it; the campaign carries
 * on to the next person.
 */
class BroadcastSender
{
    public function __construct(
        private OutboundConversationResolver $conversations,
        private VariableResolver $variables,
        private MessageService $messages,
    ) {}

    public function send(Broadcast $broadcast, BroadcastRecipient $recipient): RecipientStatus
    {
        $recipient->increment('attempts');

        try {
            return $this->deliver($broadcast, $recipient);
        } catch (\Throwable $th) {
            Log::error('BroadcastSender: send failed', [
                'broadcast_id' => $broadcast->id,
                'recipient_id' => $recipient->id,
                'error' => $th->getMessage(),
            ]);

            return $this->finish($recipient, RecipientStatus::Failed, $th->getMessage());
        }
    }

    private function deliver(Broadcast $broadcast, BroadcastRecipient $recipient): RecipientStatus
    {
        $connection = $broadcast->connection;
        $contact = $this->contactFor($broadcast, $recipient);

        if ($contact->hasOptedOutOfBroadcasts()) {
            return $this->finish($recipient, RecipientStatus::Skipped, 'Contact opted out of broadcasts');
        }

        // A group chat is not a person; a campaign addressed at one would blast
        // every member of it.
        if ($contact->is_group) {
            return $this->finish($recipient, RecipientStatus::Skipped, 'Group conversations cannot receive campaigns');
        }

        $resolved = $this->conversations->resolve(
            $connection,
            $contact,
            assignedUserId: $broadcast->created_by,
            emailSubject: $broadcast->payload['subject'] ?? null,
            // A thread the AI is mid-turn on must not be yanked to Active by a
            // campaign; the promotion below is deliberate and narrower.
            activateOnReuse: false,
        );

        if (! $resolved) {
            return $this->finish(
                $recipient,
                RecipientStatus::Skipped,
                'No conversation to reach this contact through — the customer has to message first on this channel',
            );
        }

        $conversation = $resolved->conversation;

        // Free-form content outside the session window is accepted by the Cloud
        // API and rejected minutes later by webhook, so asking first is the only
        // way to report the truth. A template is exempt: that is what it is for.
        if ($broadcast->content_type->isFreeForm() && MessagingWindow::appliesTo($conversation) && ! MessagingWindow::isOpen($conversation)) {
            if ($resolved->wasCreated) {
                $conversation->delete();
            }

            return $this->finish(
                $recipient,
                RecipientStatus::Skipped,
                'The messaging window for this contact has closed — only an approved template can reach them now',
            );
        }

        try {
            $message = $this->dispatchMessage($broadcast, $conversation, $recipient);
        } catch (\Throwable $th) {
            // Never leave an empty thread behind for a send that never landed —
            // at campaign scale that is thousands of blank conversations.
            if ($resolved->wasCreated) {
                $conversation->delete();
            }

            throw $th;
        }

        $message?->update(['sent_by_user_id' => $broadcast->created_by]);

        $this->settleConversation($broadcast, $conversation);

        $recipient->forceFill([
            'conversation_id' => $conversation->id,
            'message_id' => $message?->id,
        ]);

        $this->announce($conversation, $message);

        return $this->finish($recipient, RecipientStatus::Sent, null);
    }

    /**
     * The contact this recipient stands for, created on the spot when the
     * address was typed by hand rather than picked from the contact book.
     */
    private function contactFor(Broadcast $broadcast, BroadcastRecipient $recipient): Contact
    {
        $contact = $recipient->contact_id
            ? $recipient->getRelationValue('contact')
            : null;

        if (! $contact) {
            $contact = Contact::createFromExternalData(
                $broadcast->connection,
                $recipient->address,
                $recipient->name ?: $recipient->address,
            );

            $recipient->contact_id = $contact->id;
            $recipient->setRelation('contact', $contact);
        }

        return $contact;
    }

    /** Hand the personalised payload to the channel's own send path. */
    private function dispatchMessage(Broadcast $broadcast, Conversation $conversation, BroadcastRecipient $recipient): ?Message
    {
        $payload = $this->variables->resolve($broadcast->payload, $recipient);

        return match ($broadcast->content_type) {
            ContentType::Template => $this->messages->sendTemplate($conversation, [
                'template_name' => $payload['template_name'],
                'language' => $payload['language'],
                'components' => $payload['components'] ?? null,
            ]),

            ContentType::Text => $this->messages->sendMessage($conversation, [
                'message' => $payload['body'],
            ]),

            ContentType::Media => $this->sendMedia($conversation, $payload),

            ContentType::Email => (new EmailHandler())->sendNewEmail(
                $conversation,
                (string) ($payload['subject'] ?? ''),
                (string) ($payload['body'] ?? ''),
            ),
        };
    }

    private function sendMedia(Conversation $conversation, array $payload): ?Message
    {
        // Media always travels as a public URL here. A campaign cannot re-upload
        // the same file per recipient, and every channel's send path already
        // accepts media_url as an alternative to an uploaded file.
        $data = [
            'media_url' => $payload['media_url'],
            'message' => $payload['caption'] ?? null,
        ];

        return match ($payload['media_type'] ?? 'image') {
            'video' => $this->messages->sendVideo($conversation, $data),
            'document' => $this->messages->sendDocument($conversation, $data),
            'audio' => $this->messages->sendAudio($conversation, $data),
            default => $this->messages->sendImage($conversation, $data),
        };
    }

    /**
     * Put the thread in a state an agent can pick up from: out of the unassigned
     * queue, owned by whoever ran the campaign, and tagged with it.
     *
     * An AI-handled thread keeps its status — the campaign message joins the
     * conversation without ending the AI's turn.
     */
    private function settleConversation(Broadcast $broadcast, Conversation $conversation): void
    {
        $updates = [];

        if ($conversation->status === ConversationStatus::Pending) {
            $updates['status'] = ConversationStatus::Active;
        }

        if (! $conversation->user_id) {
            $updates['user_id'] = $broadcast->created_by;
        }

        if ($updates !== []) {
            $conversation->update($updates);
        }

        if ($broadcast->tag_id) {
            // syncWithoutDetaching, not attach: a contact reached by two
            // campaigns must not collect a duplicate pivot row.
            $conversation->tags()->syncWithoutDetaching([$broadcast->tag_id]);
        }
    }

    /**
     * Tell every open dashboard. `conversation-updated` is what makes a brand
     * new thread appear in the inbox without a refresh (the listener writes it
     * straight into IndexedDB); `message-received` fills the thread itself.
     *
     * No notification storm: the front end only rings for incoming messages,
     * and everything a campaign sends is outgoing.
     */
    private function announce(Conversation $conversation, ?Message $message): void
    {
        if ($message) {
            broadcast(new MessageReceived($message));
        }

        broadcast(new ConversationUpdated($conversation->load('contact')));
    }

    private function finish(BroadcastRecipient $recipient, RecipientStatus $status, ?string $error): RecipientStatus
    {
        $recipient->forceFill([
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 2000) : null,
            'sent_at' => $status === RecipientStatus::Sent ? now() : null,
        ])->save();

        return $status;
    }
}
