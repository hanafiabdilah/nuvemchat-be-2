<?php

namespace App\Services\Message;

use App\Events\MessageUpdated;
use App\Models\ApiwayQueuedSend;
use App\Models\Connection;
use App\Models\Message;
use App\Services\Connection\Proxy\ApiwayConfig;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What became of a message API Way put in its anti-ban queue.
 *
 * Since 19 Oct 2026 every `send-*` call on the core goes through a per-number
 * send policy (13/min, a 3–8 s gap, 800 a day by default). Within it the core
 * answers as before, with the WhatsApp message id. Past it the core answers
 * 202 `{status: "queued", messageId: <uuid>}` and sends later — seconds later
 * when it is only pacing, the next day at 00:00 UTC once the daily cap is hit.
 *
 * The dashboard used to draw that answer as a sent message: one tick, the same
 * as anything WhatsApp accepted. So an agent saw a flow's five bubbles "sent"
 * at 08:22 that would not reach the customer until 21:00, and read the
 * customer's silence as an answer.
 *
 * Two halves, kept in this one class:
 *
 *  - track(): called by every API Way send right after the message is stored.
 *    Marks `meta.send_queue` and opens a row in `apiway_queued_sends`.
 *  - sync(): `apiway:sync-send-queue`, every minute. Reads the core's send log
 *    and moves each held message to sent, failed or unconfirmed, broadcasting
 *    the change so the bubble updates where it is.
 *
 * What it cannot do: tick a queued message delivered or read. The send log
 * names the queue entry, not the WhatsApp message it became, and WhatsApp's
 * receipts only carry the latter — so for these messages "sent" is as far as
 * the dashboard can honestly go. Closing that needs the core to report the
 * WhatsApp id of a queued send.
 */
final class ApiwaySendQueue
{
    /** `messages.meta` key; MessageResource publishes it under the same name. */
    public const META_KEY = 'send_queue';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /**
     * The core gave up without knowing whether WhatsApp took it (its own
     * `timeout` code), or the entry never turned up in the send log.
     */
    public const UNCONFIRMED = 'unconfirmed';

    /** Past this, a message still missing from the send log stops being looked for. */
    public const GIVE_UP_AFTER_HOURS = 72;

    /** A scheduled time closer than this is checked on the next pass anyway. */
    private const NEAR_SECONDS = 90;

    /**
     * A message held for later is still looked at this often, not only at
     * its scheduled time: the core can send, move or drop it before then — a
     * policy changed in another panel did exactly that, and the dashboard
     * kept promising 21:00 for messages that no longer existed.
     */
    private const RECHECK_MINUTES = 15;

    /**
     * How long a message may be missing from both the queue and the send log
     * before it counts as dropped. The core writes its log entry when it
     * accepts the send, so this is only a margin against reading the two in
     * the middle of that.
     */
    private const VANISHED_AFTER_MINUTES = 10;

    /** Send-log pages read per connection per pass (the core allows 200 rows a page). */
    private const MAX_PAGES = 40;

    private const PAGE_SIZE = 200;

    /**
     * Ours, in English — the core's own sentences go to the log only. The
     * dashboard translates by `error_code`; this is what lands in
     * `messages.error`, which the health counters and older clients read.
     */
    private const FAILURE_COPY = [
        'reachout_timelock' => 'WhatsApp refused to start a conversation from this number (error 463): the number is new, or it sent too fast to contacts who have not saved it.',
        'whatsapp_rejected' => 'WhatsApp refused this message.',
        'media_fetch_failed' => 'API Way could not download the file to send it.',
        'instance_not_connected' => 'The WhatsApp number was disconnected when API Way tried to send it.',
        'canceled' => 'The message was removed from the API Way queue before it was sent.',
        'discarded' => 'The message was removed from the API Way queue before it was sent.',
    ];

    private const FAILURE_FALLBACK = 'API Way could not send this message.';

    /**
     * The queue entry's id, when this send response is the core's 202 — null
     * for a message the core sent on the spot.
     */
    public static function queuedId(array $response): ?string
    {
        if (($response['status'] ?? null) !== 'queued') {
            return null;
        }

        $id = $response['messageId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @return array<string, mixed> */
    public static function initialState(string $queueId): array
    {
        return [
            'id' => $queueId,
            'status' => self::QUEUED,
            'scheduled_at' => null,
            'sent_at' => null,
            'error_code' => null,
        ];
    }

    /**
     * Called by each API Way send once the message is stored with the core's
     * response as its meta. Never throws — the message is already in the
     * core's hands, and failing the send now would invite a second one.
     */
    public static function track(Message $message, Connection $channelConnection): void
    {
        $queueId = self::queuedId($message->meta ?? []);

        if ($queueId === null) {
            return;
        }

        try {
            $message->forceFill([
                'meta' => array_merge($message->meta ?? [], [self::META_KEY => self::initialState($queueId)]),
            ])->saveQuietly();

            ApiwayQueuedSend::query()->firstOrCreate(
                ['message_id' => $message->id],
                ['connection_id' => $channelConnection->id, 'queue_id' => $queueId],
            );
        } catch (\Throwable $e) {
            Log::warning('ApiwaySendQueue: could not track a queued send', [
                'message_id' => $message->id,
                'queue_id' => $queueId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Settle what can be settled for one connection's held messages.
     *
     * @param  Collection<int, ApiwayQueuedSend>  $rows
     * @return array{settled: int, waiting: int}
     */
    public function sync(Connection $channelConnection, Collection $rows): array
    {
        $stats = ['settled' => 0, 'waiting' => 0];

        if ($rows->isEmpty()) {
            return $stats;
        }

        $credentials = $channelConnection->credentials ?? [];
        $log = filled($credentials['token'] ?? null) && filled($credentials['instance_id'] ?? null)
            ? $this->readSendLog($channelConnection, $rows)
            : null;
        $entries = $log['entries'] ?? null;

        // Only asked for when something is missing from the log, and only
        // trusted when the log was read to its end — see vanished().
        $stillQueued = null;

        foreach ($rows as $row) {
            $message = $row->message;

            if (! $message) {
                $row->delete();

                continue;
            }

            $entry = $entries[$row->queue_id] ?? null;

            if ($entry === null) {
                if (($log['complete'] ?? false) && $row->created_at->lt(now()->subMinutes(self::VANISHED_AFTER_MINUTES))) {
                    $stillQueued ??= $this->queuedIds($channelConnection);

                    if ($stillQueued !== null && ! in_array($row->queue_id, $stillQueued, true)) {
                        $this->vanished($row, $message);
                        $stats['settled']++;

                        continue;
                    }
                }

                if ($row->created_at->lt(now()->subHours(self::GIVE_UP_AFTER_HOURS))) {
                    $this->settle($row, $message, self::UNCONFIRMED, null, null);
                    $stats['settled']++;
                } else {
                    $stats['waiting']++;
                }

                continue;
            }

            if ($this->apply($row, $message, $entry)) {
                $stats['settled']++;
            } else {
                $stats['waiting']++;
            }
        }

        return $stats;
    }

    /**
     * Settle messages someone removed from the core's queue (the "clear the
     * queue" action). The caller has already let the send log settle the ones
     * that went out first, so whatever is left here never reached anybody.
     *
     * @param  Collection<int, ApiwayQueuedSend>  $rows
     */
    public function markRemoved(Collection $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            if ($row->message) {
                $this->settle($row, $row->message, self::FAILED, null, 'canceled');
                $count++;
            } else {
                $row->delete();
            }
        }

        return $count;
    }

    /**
     * A message the core no longer has anywhere: not waiting in its queue,
     * and not in its send log, which records every send — queued or direct,
     * sent or failed. Seen in production when an admin switched a number's
     * queue off: everything still held at that moment vanished from both,
     * unsent. "Removed before it was sent" is what the core's own records say
     * happened, so that is what the bubble says.
     */
    private function vanished(ApiwayQueuedSend $row, Message $message): void
    {
        Log::warning('ApiwaySendQueue: a held message is gone from the core without being sent', [
            'message_id' => $message->id,
            'queue_id' => $row->queue_id,
            'connection_id' => $row->connection_id,
        ]);

        $this->settle($row, $message, self::FAILED, null, 'discarded');
    }

    /**
     * Ids the core still holds in its queue for this number. Null when the
     * queue could not be read — and then nothing is called dropped.
     *
     * @return list<string>|null
     */
    private function queuedIds(Connection $channelConnection): ?array
    {
        try {
            $response = Http::withToken($channelConnection->credentials['token'])
                ->timeout(20)
                ->get(ApiwayConfig::baseUrl() . '/v1/instance/quere', [
                    'instanceId' => $channelConnection->credentials['instance_id'],
                ]);
        } catch (\Throwable) {
            return null;
        }

        $data = $response->successful() ? $response->json('data') : null;

        if (! is_array($data)) {
            return null;
        }

        return array_values(array_filter(
            array_map(fn ($entry) => is_array($entry) ? ($entry['id'] ?? null) : null, $data),
            'is_string',
        ));
    }

    /**
     * Send-log entries for these rows, keyed by queue id. Null when the core
     * could not be read — the rows simply wait for the next pass.
     *
     * The log is filtered by creation time and newest first, so the window is
     * the span the held messages were created in, paged until every one of
     * them has turned up. `complete` says the whole span was read: only then
     * does a missing entry mean something.
     *
     * @param  Collection<int, ApiwayQueuedSend>  $rows
     * @return array{entries: array<string, array<string, mixed>>, complete: bool}|null
     */
    private function readSendLog(Connection $channelConnection, Collection $rows): ?array
    {
        $wanted = $rows->pluck('queue_id')->flip();
        $from = $rows->min('created_at')->copy()->subMinutes(2);
        $to = $rows->max('created_at')->copy()->addMinutes(2);
        $found = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = array_filter([
                'instanceId' => $channelConnection->credentials['instance_id'],
                'from' => $this->rfc3339($from),
                'to' => $this->rfc3339($to),
                'limit' => self::PAGE_SIZE,
                'cursor' => $cursor,
            ], fn ($value) => $value !== null);

            try {
                $response = Http::withToken($channelConnection->credentials['token'])
                    ->timeout(20)
                    ->get(ApiwayConfig::baseUrl() . '/v1/instance/send-log', $query);
            } catch (\Throwable $e) {
                Log::warning('ApiwaySendQueue: send log unreachable', [
                    'connection_id' => $channelConnection->id,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            if (! $response->successful()) {
                Log::warning('ApiwaySendQueue: core refused the send log', [
                    'connection_id' => $channelConnection->id,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            foreach ((array) $response->json('data', []) as $entry) {
                $id = is_array($entry) ? ($entry['id'] ?? null) : null;

                if (is_string($id) && $wanted->has($id)) {
                    $found[$id] = $entry;
                }
            }

            $cursor = $response->json('next_cursor');
            $pages++;
        } while (filled($cursor) && count($found) < $wanted->count() && $pages < self::MAX_PAGES);

        return ['entries' => $found, 'complete' => blank($cursor)];
    }

    /** True when the message is settled and its row gone. */
    private function apply(ApiwayQueuedSend $row, Message $message, array $entry): bool
    {
        $status = (string) ($entry['status'] ?? '');

        if ($status === 'sent') {
            $this->settle($row, $message, self::SENT, $this->timestamp($entry['sent_at'] ?? null), null);

            return true;
        }

        if ($status === 'failed') {
            $code = is_array($entry['error'] ?? null) ? ($entry['error']['code'] ?? null) : null;
            $code = is_string($code) && $code !== '' ? $code : 'send_failed';

            Log::info('ApiwaySendQueue: the core could not send a queued message', [
                'message_id' => $message->id,
                'queue_id' => $row->queue_id,
                'error' => $entry['error'] ?? null,
            ]);

            // The core's own word for "it may well have been delivered".
            $this->settle($row, $message, $code === 'timeout' ? self::UNCONFIRMED : self::FAILED, null, $code);

            return true;
        }

        // Still `queued` or `sending`: record when the core means to send it,
        // and look again by then — or sooner, see RECHECK_MINUTES.
        $scheduledAt = $this->timestamp($entry['scheduled_at'] ?? null);
        $state = $message->meta[self::META_KEY] ?? self::initialState($row->queue_id);

        if (($state['scheduled_at'] ?? null) !== $scheduledAt) {
            $this->write($message, array_merge($state, ['status' => self::QUEUED, 'scheduled_at' => $scheduledAt]));
        }

        $at = $scheduledAt !== null ? Carbon::parse($scheduledAt) : null;

        $row->update([
            'check_after' => $at !== null && $at->gt(now()->addSeconds(self::NEAR_SECONDS))
                ? $at->min(now()->addMinutes(self::RECHECK_MINUTES))
                : null,
        ]);

        return false;
    }

    private function settle(ApiwayQueuedSend $row, Message $message, string $status, ?string $sentAt, ?string $errorCode): void
    {
        $state = $message->meta[self::META_KEY] ?? self::initialState($row->queue_id);

        $this->write($message, array_merge($state, [
            'status' => $status,
            'sent_at' => $sentAt,
            'error_code' => $errorCode,
        ]), $status === self::FAILED ? (self::FAILURE_COPY[$errorCode] ?? self::FAILURE_FALLBACK) : null);

        $row->delete();
    }

    private function write(Message $message, array $state, ?string $error = null): void
    {
        $attributes = ['meta' => array_merge($message->meta ?? [], [self::META_KEY => $state])];

        if ($error !== null) {
            $attributes['error'] = $error;
        }

        // A plain update: `updated_at` is the delta-sync cursor, and a tab that
        // was closed when this changed must still find it on its next sync.
        $message->update($attributes);

        try {
            broadcast(new MessageUpdated($message));
        } catch (\Throwable $e) {
            Log::warning('ApiwaySendQueue: could not broadcast a queue update', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return $this->rfc3339(Carbon::parse($value));
        } catch (\Throwable) {
            return null;
        }
    }

    private function rfc3339(CarbonInterface $at): string
    {
        return $at->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
