<?php

namespace App\Services\Incident;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Incident\DeliveryKind;
use App\Enums\Incident\DeliverySource;
use App\Enums\Incident\DeliveryStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\SendIncidentNotice;
use App\Models\Conversation;
use App\Models\Incident;
use App\Models\IncidentDelivery;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * The gate every customer message passes on its way to the flow, while an
 * incident is on.
 *
 * Called by the eight places that hand an incoming message to FlowRunner —
 * the seven chat handlers and the widget — at the same point the WhatsApp
 * Official handler already stops for taps on another system's buttons: after
 * the message is stored and announced, before anything answers it. True means
 * "the incident answered this one; do not run the flow on it".
 *
 * Three rules, and each one is there because the version without it went
 * wrong in the case that started this feature (ProxyBR's DNS outage, answered
 * from outside through the API):
 *
 *  - **Once per conversation.** One row per (incident, conversation, kind) is
 *    claimed with an insert that a unique index makes atomic, so a customer
 *    who sends five messages in a burst gets one notice — and two webhooks
 *    landing in the same millisecond cannot both send it.
 *  - **The message the notice answered is not answered again.** It is marked
 *    `meta.incident_handled`, and every reader that turns customer messages
 *    into flow input skips it (Message::exceptIncidentHandled): without that
 *    the AI turn already scheduled for the customer's "oi" would wake up, read
 *    "meu proxy caiu" from the database, and answer it a second time.
 *  - **An attendant's conversation is left to the attendant**, unless the
 *    incident says otherwise. No row is written for it, so if it goes back to
 *    the queue the next message can still be judged.
 *
 * Never throws: an incident that cannot be read is no reason for a customer's
 * message not to reach the flow.
 */
final class IncidentResponder
{
    /** On the customer message the notice answered. */
    public const HANDLED_META = 'incident_handled';

    /** On the notice (and the "it is fixed" message) the incident sent. */
    public const SENT_META = 'incident';

    public static function intercept(Message $message): bool
    {
        try {
            return self::handle($message);
        } catch (\Throwable $th) {
            Log::error('IncidentResponder: could not judge the message, letting it through', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'error' => $th->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The live incident covering this connection, if there is one. Only one
     * can be, because activating a second on the same connection is refused.
     */
    public static function liveFor(int $connectionId): ?Incident
    {
        return Incident::live()
            ->whereHas('connections', fn ($query) => $query->where('connections.id', $connectionId))
            ->orderBy('id')
            ->first();
    }

    /** An attendant is holding this one: it is theirs to answer. */
    public static function heldByAgent(Conversation $conversation): bool
    {
        return $conversation->user_id !== null && $conversation->status === ConversationStatus::Active;
    }

    /** Whether incidents apply to this conversation at all. */
    public static function covers(Conversation $conversation): bool
    {
        $connection = $conversation->getRelationValue('connection') ?? $conversation->connection;

        return $connection !== null
            && ! $conversation->isGroup()
            && $connection->channel !== Channel::Email;
    }

    private static function handle(Message $message): bool
    {
        if ($message->sender_type !== SenderType::Incoming || $message->message_type === MessageType::Info) {
            return false;
        }

        $conversation = $message->conversation;

        if (! $conversation || ! self::covers($conversation)) {
            return false;
        }

        $incident = self::liveFor((int) $conversation->connection_id);

        if (! $incident) {
            return false;
        }

        if (! $incident->reply_in_agent_threads && self::heldByAgent($conversation)) {
            return false;
        }

        if (self::decided($incident, $conversation)) {
            return false;
        }

        $verdict = IncidentMatcher::judgeMessage($incident, $message);

        if (! $verdict->counted()) {
            return false;
        }

        $claimed = self::claim($incident, $conversation, $message, $verdict, DeliverySource::Reactive);

        if (! $claimed || ! $verdict->matched()) {
            return false;
        }

        $delivery = IncidentDelivery::where('incident_id', $incident->id)
            ->where('conversation_id', $conversation->id)
            ->where('kind', DeliveryKind::Notice)
            ->first();

        if (! $delivery) {
            return false;
        }

        if (! $incident->continue_flow) {
            $message->forceFill([
                'meta' => array_merge((array) ($message->meta ?? []), [
                    self::HANDLED_META => ['incident_id' => $incident->id],
                ]),
            ])->saveQuietly();
        }

        SendIncidentNotice::dispatch($delivery->id)->onQueue((string) config('incidents.queue', 'default'));

        IncidentEvents::changed($incident, throttled: true);

        Log::info('IncidentResponder: notice claimed', [
            'incident_id' => $incident->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'keyword' => $verdict->keyword,
            'continue_flow' => $incident->continue_flow,
        ]);

        return ! $incident->continue_flow;
    }

    public static function decided(Incident $incident, Conversation $conversation): bool
    {
        return IncidentDelivery::where('incident_id', $incident->id)
            ->where('conversation_id', $conversation->id)
            ->where('kind', DeliveryKind::Notice)
            ->exists();
    }

    /**
     * Write the decision for this conversation, if nobody has. True when this
     * call wrote it.
     *
     * insertOrIgnore against the unique index, not exists-then-insert: two
     * messages from the same person reach two PHP workers at once often
     * enough (a burst of three lines does it), and both would see "not yet".
     */
    public static function claim(Incident $incident, Conversation $conversation, ?Message $trigger, IncidentVerdict $verdict, DeliverySource $source): bool
    {
        $now = now();

        return IncidentDelivery::query()->insertOrIgnore([
            'incident_id' => $incident->id,
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
            'kind' => DeliveryKind::Notice->value,
            'status' => ($verdict->matched() ? DeliveryStatus::Pending : DeliveryStatus::NotMatched)->value,
            'source' => $source->value,
            'matched_keyword' => $verdict->keyword !== null ? mb_substr($verdict->keyword, 0, 60) : null,
            'excluded_keyword' => $verdict->excluded !== null ? mb_substr($verdict->excluded, 0, 60) : null,
            'trigger_message_id' => $trigger?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;
    }
}
