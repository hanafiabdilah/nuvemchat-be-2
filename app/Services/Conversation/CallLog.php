<?php

namespace App\Services\Conversation;

use App\Enums\Conversation\Status;
use App\Events\ConversationUpdated;
use App\Events\MessageUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A voice call written into the thread it belongs to.
 *
 * This platform cannot answer calls on either WhatsApp channel — Cloud API
 * would need a WebRTC/SIP stack, and whatsmeow implements no call media at all
 * — so the whole feature is the record: the customer rang, at this time, and
 * this is how it ended. Without it a call is invisible here, and an agent reads
 * a thread where the customer simply went quiet.
 *
 * Stored as a `MessageType::Info` note, the same shape the transfer and
 * expired-window notes already use: it takes its place in the timeline, reaches
 * every open tab over the usual broadcast, is never handed to a channel, and —
 * because info notes are written Outgoing — never inflates the unread badge.
 *
 * One row per call, not per event. A call arrives as a sequence (ringing →
 * accepted → ended) whose parts can be re-delivered or arrive out of order, so
 * the row is keyed by the channel's own call id and each event rewrites it in
 * place. `RANK` is what makes that safe: a late "ringing" can never bury an
 * outcome that is already known.
 */
class CallLog
{
    /** Ringing now — no outcome yet. */
    public const RINGING = 'call_ringing';

    /** Accepted somewhere (the linked phone) and still running. */
    public const ONGOING = 'call_ongoing';

    /** Answered, and we know how long it lasted. */
    public const ANSWERED = 'call_answered';

    /** Answered, but the channel never told us the length. */
    public const ENDED = 'call_ended';

    /** Rang out: nobody picked up. */
    public const MISSED = 'call_missed';

    /** Actively declined. */
    public const DECLINED = 'call_declined';

    /**
     * How much each state is worth knowing. An event may only rewrite the note
     * with a state at least as final as the one already there, which is what
     * keeps a re-delivered offer from turning a finished call back into a
     * ringing one. Equal ranks do rewrite: a repeat costs one broadcast and
     * that broadcast is how a panel that was offline for the first one catches
     * up.
     */
    private const RANK = [
        self::RINGING => 0,
        self::ONGOING => 1,
        self::MISSED => 2,
        self::DECLINED => 2,
        self::ENDED => 3,
        self::ANSWERED => 4,
    ];

    /**
     * Namespaces the call id inside `messages.external_id`, which otherwise
     * holds message ids from the same channel. Nothing says the two id spaces
     * are disjoint, and a collision would make a call overwrite a message.
     */
    private const PREFIX = 'call:';

    /**
     * A call the flow placed (the Call node), as opposed to one the customer
     * made. Kept in `meta.call.direction`, and it changes the note's code —
     * `call_out_*` instead of `call_*` — because "Missed call" over a call the
     * customer never answered says the opposite of what happened.
     */
    public const OUTGOING = 'outgoing';

    /**
     * The note for a call the flow placed, written in the flow's conversation
     * as soon as the core accepts it. The core's events for the same call id
     * then rewrite it like any other call note.
     *
     * @param  array<string, mixed>  $detail
     */
    public static function outgoing(Conversation $conversation, string $callId, array $detail = []): Message
    {
        $code = self::RINGING;

        return SystemMessage::info(
            $conversation,
            self::body($code, null, true),
            self::codeFor($code, true),
            [],
            [
                'external_id' => self::PREFIX.$callId,
                'meta' => ['call' => self::detail($callId, $code, null, array_merge($detail, ['direction' => self::OUTGOING]))],
            ],
        );
    }

    /** Whether this note stands for a call the flow placed. */
    public static function isOutgoing(Message $note): bool
    {
        return data_get($note->meta, 'call.direction') === self::OUTGOING;
    }

    /**
     * Move a note we already have on to a later state — for events that find
     * the note by its call id rather than through a phone number.
     *
     * @param  array<string, mixed>  $detail
     */
    public static function progress(Message $note, string $code, ?int $seconds = null, array $detail = []): ?Message
    {
        if (! array_key_exists($code, self::RANK)) {
            Log::warning('CallLog: unknown call state', ['code' => $code, 'message_id' => $note->id]);

            return null;
        }

        return self::advance($note, $code, $seconds, $detail);
    }

    /** The code a note carries: the call's state, or its outgoing twin. */
    private static function codeFor(string $state, bool $outgoing): string
    {
        return $outgoing ? 'call_out_'.substr($state, strlen('call_')) : $state;
    }

    /**
     * The note standing for this call on this connection, if we have one.
     *
     * Scoped to the connection rather than to a conversation, exactly like the
     * inbound-message dedupe: the thread a call belongs to can be resolved
     * differently between two events (a conversation closing in between), and
     * the note still has to be found.
     */
    public static function find(Connection $connection, string $callId): ?Message
    {
        return Message::whereHas('conversation', fn ($q) => $q->where('connection_id', $connection->id))
            ->where('external_id', self::PREFIX.$callId)
            ->first();
    }

    /**
     * Record where a call has got to, creating the note or advancing it.
     *
     * @param  string  $phone  The customer's number — the contact identity
     *                         and the conversation key on both WhatsApp
     *                         channels.
     * @param  string|null  $name  Display name if the event carried one.
     * @param  string  $code  One of the constants above.
     * @param  Carbon  $at  When this happened, per the channel.
     * @param  int|null  $seconds  Length, when the channel reports it.
     * @param  array<string, mixed>  $detail  Channel facts worth keeping on the
     *                                        row (direction, status, the accept time a
     *                                        later terminate needs to compute a length).
     * @return Message|null Null when the event was older than what we already
     *                      knew and was therefore ignored.
     */
    public static function record(
        Connection $connection,
        string $phone,
        ?string $name,
        string $callId,
        string $code,
        Carbon $at,
        ?int $seconds = null,
        array $detail = [],
    ): ?Message {
        if (! array_key_exists($code, self::RANK)) {
            Log::warning('CallLog: unknown call state', ['code' => $code, 'call_id' => $callId]);

            return null;
        }

        $existing = self::find($connection, $callId);

        if ($existing) {
            return self::advance($existing, $code, $seconds, $detail);
        }

        return self::start($connection, $phone, $name, $callId, $code, $at, $seconds, $detail);
    }

    /**
     * First event for this call: find-or-create the thread, then write the note.
     *
     * A call from someone who has never written is exactly the case this
     * feature exists for, so a missing thread is created rather than skipped —
     * Pending, unassigned, the same state an inbound message opens. A Resolved
     * thread is deliberately not reopened (a new one is started instead), which
     * is how every inbound handler on these channels already behaves.
     */
    private static function start(
        Connection $connection,
        string $phone,
        ?string $name,
        string $callId,
        string $code,
        Carbon $at,
        ?int $seconds,
        array $detail,
    ): ?Message {
        $conversation = DB::transaction(function () use ($connection, $phone, $name) {
            $contact = Contact::createFromExternalData($connection, $phone, $name ?: $phone, $phone);

            $conversation = Conversation::where('external_id', $phone)
                ->where('contact_id', $contact->id)
                ->where('connection_id', $connection->id)
                ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
                ->lockForUpdate()
                ->first();

            return $conversation ?: Conversation::create([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id' => $phone,
                'status' => Status::Pending,
            ]);
        });

        $message = SystemMessage::info(
            $conversation,
            self::body($code, $seconds),
            $code,
            self::params($seconds),
            [
                'external_id' => self::PREFIX.$callId,
                'sent_at' => $at,
                'meta' => ['call' => self::detail($callId, $code, $seconds, $detail)],
            ],
        );

        broadcast(new ConversationUpdated($conversation->load('contact')));

        Log::info('CallLog: call recorded', [
            'connection_id' => $connection->id,
            'conversation_id' => $conversation->id,
            'call_id' => $callId,
            'state' => $code,
        ]);

        return $message;
    }

    /** A later event for a call we already have a note for. */
    private static function advance(Message $message, string $code, ?int $seconds, array $detail): ?Message
    {
        // The state, not the code: an outgoing note's code is `call_out_*`,
        // which RANK does not know.
        $current = data_get($message->meta, 'call.state', data_get($message->meta, 'info.code', self::RINGING));
        $outgoing = self::isOutgoing($message);

        if ((self::RANK[$code] ?? 0) < (self::RANK[$current] ?? 0)) {
            Log::info('CallLog: stale call event ignored', [
                'message_id' => $message->id,
                'current' => $current,
                'received' => $code,
            ]);

            return $message;
        }

        $meta = $message->meta ?? [];
        $call = $meta['call'] ?? [];

        // The accept time is set once, by the event that saw it, and read much
        // later by the terminate that has to work out how long the call ran.
        $meta['call'] = self::detail($call['id'] ?? null, $code, $seconds, array_merge($call, $detail));
        $meta['info'] = array_filter([
            'code' => self::codeFor($code, $outgoing),
            'params' => self::params($seconds) ?: null,
        ]);

        // sent_at is left alone on purpose: the note belongs where the call
        // started in the timeline, not where it ended.
        $message->update([
            'body' => self::body($code, $seconds, $outgoing),
            'meta' => $meta,
        ]);

        $message->refresh();

        broadcast(new MessageUpdated($message));
        broadcast(new ConversationUpdated($message->conversation->load('contact')));

        return $message;
    }

    /**
     * English copy stored on the row. The SPA translates the code instead
     * (lib/infoMessage.ts) and only falls back to this, so it has to read as a
     * finished sentence on its own.
     */
    private static function body(string $code, ?int $seconds, bool $outgoing = false): string
    {
        if ($outgoing) {
            return match ($code) {
                self::RINGING => 'The flow is calling the customer.',
                self::ONGOING => 'The customer answered the flow\'s call.',
                self::ANSWERED => 'The customer answered the flow\'s call · '.self::humanDuration($seconds ?? 0),
                self::ENDED => 'The flow\'s call ended.',
                self::DECLINED => 'The customer declined the flow\'s call.',
                default => 'The customer did not answer the flow\'s call.',
            };
        }

        return match ($code) {
            self::RINGING => 'Incoming call.',
            self::ONGOING => 'Call in progress.',
            self::ANSWERED => 'Call answered · '.self::humanDuration($seconds ?? 0),
            self::ENDED => 'Call ended.',
            self::DECLINED => 'Call declined.',
            default => 'Missed call.',
        };
    }

    /** @return array<string, mixed> */
    private static function params(?int $seconds): array
    {
        return $seconds === null ? [] : ['seconds' => $seconds];
    }

    /** @return array<string, mixed> */
    private static function detail(?string $callId, string $code, ?int $seconds, array $detail): array
    {
        return array_filter(array_merge($detail, [
            'id' => $callId ?? ($detail['id'] ?? null),
            'state' => $code,
            'seconds' => $seconds ?? ($detail['seconds'] ?? null),
        ]), fn ($value) => $value !== null);
    }

    /**
     * "45s" / "2m 30s" / "1h 5m" — mirrors formatDuration in the SPA so the
     * stored fallback and the translated copy read the same.
     */
    private static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            $rest = $seconds % 60;

            return $rest ? "{$minutes}m {$rest}s" : "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest ? "{$hours}h {$rest}m" : "{$hours}h";
    }
}
