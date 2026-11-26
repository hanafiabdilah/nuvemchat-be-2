<?php

namespace App\Services\Connection;

use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Support\SqlDialect;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Health numbers for a single connection, for the detail drawer.
 *
 * Everything here is read back out of `messages` — the table every channel
 * already writes to. There is no new write path and no sampler, which is also
 * the boundary of what this can honestly report:
 *
 *  - **Send failures** work everywhere. Every handler in V1/SendMessage has
 *    filled in `messages.error` since the first channel shipped, and until now
 *    that column was only ever visible one bubble at a time. A token that
 *    expired at noon is the wall of red here, rather than something a customer
 *    tells you about.
 *  - **Delivery** only works on the three channels that actually confirm it
 *    (see Channel::reportsDeliveryReceipts). Elsewhere the rate is omitted,
 *    not zeroed — a zero would read as "nothing arrives".
 *  - **Uptime and latency cannot be reported at all.** Nothing samples whether
 *    a connection was reachable at time T, and no outbound call is timed, so
 *    both would have to be invented. They are absent on purpose.
 */
class ConnectionActivity
{
    /** Days of history behind the health block and the sparkline. */
    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 90;

    /** Above this share of failed sends the connection is called broken. */
    private const CRITICAL_FAILURE_RATE = 5.0;

    private CarbonImmutable $from;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $days = self::DEFAULT_DAYS,
        private readonly int $offsetSeconds = 0,
    ) {
        // Whole days back from the start of today in the viewer's clock, so the
        // sparkline's last bucket is "today" rather than a partial 24h window
        // that slides every time the drawer is opened.
        $this->from = CarbonImmutable::now()
            ->addSeconds($this->offsetSeconds)
            ->startOfDay()
            ->subDays(max(1, $this->days) - 1)
            ->subSeconds($this->offsetSeconds);
    }

    public function build(): array
    {
        $daily = $this->daily();

        return [
            'health' => $this->health($daily),
        ];
    }

    /**
     * One row per calendar day, zero-filled. The gaps have to be real zeroes:
     * a sparkline drawn only from the days that had traffic turns a two-day
     * outage into a straight line.
     */
    private function daily(): array
    {
        $date = SqlDialect::date('messages.created_at', $this->offsetSeconds);
        $incoming = SenderType::Incoming->value;
        $outgoing = SenderType::Outgoing->value;

        $rows = $this->scopedMessages()
            ->groupBy(DB::raw($date))
            ->select(
                DB::raw("{$date} as day"),
                DB::raw("SUM(CASE WHEN messages.sender_type = '{$incoming}' THEN 1 ELSE 0 END) as received"),
                DB::raw("SUM(CASE WHEN messages.sender_type = '{$outgoing}' THEN 1 ELSE 0 END) as sent"),
                DB::raw("SUM(CASE WHEN messages.error IS NOT NULL THEN 1 ELSE 0 END) as failed"),
                DB::raw("SUM(CASE WHEN messages.sender_type = '{$outgoing}' AND messages.delivery_at IS NOT NULL THEN 1 ELSE 0 END) as delivered")
            )
            ->get()
            ->keyBy('day');

        $days = [];
        $cursor = $this->from->addSeconds($this->offsetSeconds);

        for ($i = 0; $i < max(1, $this->days); $i++) {
            $key = $cursor->addDays($i)->format('Y-m-d');
            $row = $rows->get($key);

            $days[] = [
                'date' => $key,
                'received' => (int) ($row->received ?? 0),
                'sent' => (int) ($row->sent ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'delivered' => (int) ($row->delivered ?? 0),
            ];
        }

        return $days;
    }

    private function health(array $daily): array
    {
        $sent = array_sum(array_column($daily, 'sent'));
        $received = array_sum(array_column($daily, 'received'));
        $failed = array_sum(array_column($daily, 'failed'));
        $delivered = array_sum(array_column($daily, 'delivered'));

        $failureRate = $sent > 0 ? round(($failed / $sent) * 100, 1) : 0.0;
        $reportsDelivery = $this->connection->channel->reportsDeliveryReceipts();

        return [
            'window_days' => max(1, $this->days),
            'verdict' => $this->verdict($sent, $failed, $failureRate),
            'sent' => $sent,
            'received' => $received,
            'failed' => $failed,
            'failure_rate' => $failureRate,
            'last_error' => $this->lastError(),
            // Null, not zero, where the channel never confirms delivery — the
            // client renders the metric only when this is non-null.
            'reports_delivery' => $reportsDelivery,
            'delivered' => $reportsDelivery ? $delivered : null,
            'delivery_rate' => $reportsDelivery && $sent > 0
                ? round(($delivered / $sent) * 100, 1)
                : null,
            'daily' => $daily,
        ];
    }

    /**
     * A word for the number, so the drawer leads with a judgement instead of a
     * percentage the reader has to interpret.
     *
     * `offline` and `idle` are separate from the failure grades on purpose: a
     * disconnected channel and a working channel nobody used this week both
     * have a 0% failure rate, and calling either "excellent" would be a lie of
     * a different kind in each case.
     */
    private function verdict(int $sent, int $failed, float $failureRate): string
    {
        if ($this->connection->status !== ConnectionStatus::Active) {
            return 'offline';
        }

        if ($sent === 0) {
            return 'idle';
        }

        if ($failed === 0) {
            return 'excellent';
        }

        return $failureRate >= self::CRITICAL_FAILURE_RATE ? 'critical' : 'attention';
    }

    /** The most recent refusal, so the failure count arrives with a cause. */
    private function lastError(): ?array
    {
        $row = $this->scopedMessages()
            ->whereNotNull('messages.error')
            ->orderByDesc('messages.id')
            ->select('messages.error', 'messages.created_at')
            ->first();

        return $row ? [
            'message' => (string) $row->error,
            'at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
        ] : null;
    }

    /**
     * Messages on this connection inside the window. Joined through
     * conversations rather than filtered with whereHas so the planner can use
     * the connection_id index instead of testing an EXISTS per candidate row.
     *
     * Internal notes are left out. They are stored as Outgoing rows (transfer,
     * status change, call log…) but never go to the channel, so counting them
     * inflated "Sent" and, since none is ever delivered, pulled the delivery
     * rate down on exactly the busy threads people pass between each other.
     */
    private function scopedMessages()
    {
        return DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.connection_id', $this->connection->id)
            ->where('messages.created_at', '>=', $this->from)
            ->where('messages.message_type', '!=', MessageType::Info->value);
    }
}
