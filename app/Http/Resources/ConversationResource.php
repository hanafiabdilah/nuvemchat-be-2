<?php

namespace App\Http\Resources;

use App\Enums\Message\SenderType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    /**
     * Set for payloads that go out on the connection channel, which every
     * agent of the inbox shares: an exclusive thread is always masked there,
     * whoever triggered the event. Its readers get the content through the
     * events addressed to their own channel (MessageReceived/MessageUpdated).
     */
    public bool $forSharedChannel = false;

    /** The payload for the shared connection channel — see $forSharedChannel. */
    public static function forSharedChannel(mixed $conversation): array
    {
        $resource = new static($conversation);
        $resource->forSharedChannel = true;

        return $resource->resolve();
    }

    /**
     * Whether this payload must hide the thread's content: exclusive, and the
     * audience is not known to be one of its readers. No authenticated user
     * (a queued broadcast) counts as unknown.
     */
    private function masksContent(Request $request): bool
    {
        if ($this->exclusive_at === null) {
            return false;
        }

        if ($this->forSharedChannel) {
            return true;
        }

        $user = $request->user();

        return ! ($user instanceof \App\Models\User && $this->resource->isReadableBy($user));
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $masked = $this->masksContent($request);

        $message = new MessageResource($this->last_message);
        $message->withoutAttachmentUrl = true;

        return [
            'id' => $this->id,
            'connection_id' => $this->connection_id,
            'type' => $this->type?->value ?? 'private',
            'status' => $this->status->value,
            'needs_human' => (bool) $this->needs_human,
            'handoff_reason' => $this->handoff_reason,
            'handoff_at' => $this->handoff_at?->timestamp,
            // When this thread was closed. Sent so the dashboard can draw the
            // Reopen button's window without a second request — the tolerance
            // itself already rides on the connection. Null on rows closed
            // before the column existed (never backfilled: a guess would
            // invent resolution times), where the client falls back the same
            // way ConversationReopen::closedAt() does.
            'resolved_at' => $this->resolved_at?->timestamp,
            // Muted threads keep syncing and keep their unread badge; they just
            // raise no toast and play no sound.
            'muted' => $this->muted_at !== null,
            // Exclusive: only the assignee and the owners may read this
            // thread. `exclusive_masked` tells the client that what follows
            // was withheld for this audience — a reader receiving a masked
            // copy (the shared channel) keeps the preview it already has.
            'exclusive' => $this->exclusive_at !== null,
            'exclusive_masked' => $masked,
            'last_message' => $masked ? self::maskedMessage($this->last_message) : $message,
            'last_message_at' => $this->last_message_at?->timestamp,
            // Prefer the withCount aggregate when the query provided it (sync
            // pages) — the fallback query runs once per conversation otherwise.
            'unread' => $this->unread_count ?? $this->messages()->where('sender_type', SenderType::Incoming)->whereNull('read_at')->count(),
            // Same deal: counted by the sync page's query, counted per row on
            // the single-conversation paths (a broadcast, an accept). Always a
            // number — a key that disappeared on a broadcast would take the
            // list's note marker with it until the next full sync.
            'notes_count' => $this->resource->notes_count ?? $this->notes()->count(),
            'contact' => ContactResource::make($this->contact),
            'participants' => ContactResource::collection($this->whenLoaded('participants')),
            'tags' => TagResource::collection($this->tags),
            'agent' => UserResource::make($this->agent),
            // Flow state carries what the customer answered (state_data).
            'flow_state' => ! $masked && $this->flowState ? new FlowStateResource($this->flowState) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The last message with everything said stripped out: enough for the list
     * to order and label the row (id, direction, time), nothing of what was
     * written, sent or attached.
     */
    private static function maskedMessage(?\App\Models\Message $message): ?array
    {
        if (! $message) {
            return null;
        }

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_type' => $message->sender_type?->value,
            'message_type' => 'text',
            'body' => null,
            'meta' => null,
            'sender' => null,
            'sent_at' => $message->sent_at,
            'created_at' => $message->created_at?->timestamp,
            'masked' => true,
        ];
    }
}
