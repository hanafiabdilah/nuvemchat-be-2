<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Broadcast\Status as BroadcastStatus;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiwaySubscription;
use App\Models\Broadcast;
use App\Models\Connection;
use App\Models\SystemHeartbeat;
use App\Support\Heartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Back Office: is the machinery running?
 *
 * Most of what keeps this platform working never serves an HTTP request — the
 * Discord gateway daemon, two queue workers, and a scheduler that renews API
 * Way subscriptions, purges media and pumps campaigns. When one of them stops,
 * the product carries on looking perfectly healthy: conversations just quietly
 * stop arriving, or a renewal window passes and ProxyBR revokes an instance
 * permanently, with no grace period and nothing on any screen that said so.
 *
 * Every check answers the same question in the same shape — ok / warn / down,
 * plus what it means — so an operator reads one page instead of correlating a
 * log file with a supervisor status.
 */
class AdminHealthController extends Controller
{
    public function index()
    {
        $checks = array_merge(
            $this->processes(),
            [
                $this->queueDepth(),
                $this->failedJobs(),
                $this->stalledBroadcasts(),
                $this->apiwayRenewals(),
                $this->apiwayUndelivered(),
                $this->emailSync(),
                $this->brokenConnections(),
            ],
        );

        return response()->json([
            'data' => [
                // The worst verdict present, so the nav badge and the page
                // header agree without re-deriving the rule.
                'status' => $this->worst(array_column($checks, 'status')),
                'checked_at' => now()->toIso8601String(),
                'checks' => $checks,
            ],
        ]);
    }

    /**
     * One check per background process, from the heartbeats they write.
     *
     * A process that has never checked in reads `unknown`, not `down`: on a
     * fresh deploy nothing has pinged yet, and claiming an outage on every
     * install's first minute would teach operators to ignore this page.
     */
    private function processes(): array
    {
        $beats = SystemHeartbeat::all()->keyBy('name');

        $out = [];

        foreach (Heartbeat::PROCESSES as $name => [$label, $expected, $why]) {
            $beat = $beats[$name] ?? null;
            $verdict = Heartbeat::verdict($beat?->beat_at, $expected);

            $out[] = [
                'key' => "process:{$name}",
                'group' => 'Processes',
                'label' => $label,
                'status' => match ($verdict) {
                    'ok' => 'ok',
                    'late' => 'warn',
                    'down' => 'down',
                    default => 'unknown',
                },
                'value' => $beat?->beat_at?->diffForHumans() ?? 'never',
                'detail' => $why,
                'meta' => [
                    'last_beat_at' => $beat?->beat_at?->toIso8601String(),
                    'expected_interval_seconds' => $expected,
                    'extra' => $beat?->meta,
                ],
            ];
        }

        return $out;
    }

    /** Jobs waiting to be picked up. A rising queue is a worker that stopped. */
    private function queueDepth(): array
    {
        if (! Schema::hasTable('jobs')) {
            return $this->check('queue:depth', 'Queue', 'Pending jobs', 'unknown', '—', 'The jobs table is not present (queue driver is not `database`).');
        }

        $byQueue = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as c')
            ->groupBy('queue')
            ->pluck('c', 'queue');

        $total = (int) $byQueue->sum();

        return $this->check(
            'queue:depth',
            'Queue',
            'Pending jobs',
            // Thresholds are deliberately loose: a burst is normal, a backlog
            // that a worker is not draining is what matters, and the process
            // heartbeats above already answer "is the worker alive".
            match (true) {
                $total > 5000 => 'down',
                $total > 500 => 'warn',
                default => 'ok',
            },
            (string) $total,
            'Work queued and not yet picked up, per queue.',
            ['by_queue' => $byQueue],
        );
    }

    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return $this->check('queue:failed', 'Queue', 'Failed jobs (24h)', 'unknown', '—', 'The failed_jobs table is not present.');
        }

        $recent = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $total = DB::table('failed_jobs')->count();

        return $this->check(
            'queue:failed',
            'Queue',
            'Failed jobs (24h)',
            match (true) {
                $recent > 100 => 'down',
                $recent > 0 => 'warn',
                default => 'ok',
            },
            (string) $recent,
            'Jobs that exhausted their retries. Each one is work a customer expected that never happened.',
            ['total' => $total],
        );
    }

    /** Running campaigns whose pump died with its worker. */
    private function stalledBroadcasts(): array
    {
        $stalled = Broadcast::query()
            ->where('status', BroadcastStatus::Running->value)
            ->where(fn ($q) => $q
                ->whereNull('last_tick_at')
                ->orWhere('last_tick_at', '<', now()->subMinutes(2)))
            ->count();

        return $this->check(
            'broadcasts:stalled',
            'Broadcasts',
            'Stalled campaigns',
            $stalled > 0 ? 'warn' : 'ok',
            (string) $stalled,
            'Campaigns marked running whose pump has not claimed a batch recently. `broadcasts:tick` revives these — if the number does not fall, the watchdog is not running either.',
        );
    }

    /**
     * API Way renewals are the one deadline on this platform with no second
     * chance: ProxyBR revokes on expiry, permanently, with no grace period.
     */
    private function apiwayRenewals(): array
    {
        $live = [
            ApiwaySubscriptionStatus::Active->value,
            ApiwaySubscriptionStatus::Provisioning->value,
        ];

        $expiringSoon = ApiwaySubscription::query()
            ->whereIn('status', $live)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(3)])
            ->count();

        $alreadyOverdue = ApiwaySubscription::query()
            ->whereIn('status', $live)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();

        return $this->check(
            'apiway:renewals',
            'API Way',
            'Instances near expiry',
            match (true) {
                $alreadyOverdue > 0 => 'down',
                $expiringSoon > 0 => 'warn',
                default => 'ok',
            },
            (string) ($expiringSoon + $alreadyOverdue),
            'ProxyBR has no grace period — an instance past its expiry is revoked permanently, not suspended.',
            ['expiring_3d' => $expiringSoon, 'overdue' => $alreadyOverdue],
        );
    }

    /**
     * Purchases where money moved and nothing was delivered.
     *
     * Provisioning happens after the charge settles, so every failure past
     * that point leaves a customer who paid for an instance they do not have.
     * The row was already flagged `needs_refund` in `meta` — nothing has ever
     * read it, which made a captured payment with no instance the one incident
     * on this platform with no screen at all. Holds are counted beside it: a
     * hold is not yet an incident, but an operator raising ProxyBR's cap now
     * is what stops it from becoming one.
     */
    private function apiwayUndelivered(): array
    {
        $rows = ApiwaySubscription::query()
            ->needsAttention()
            ->with('tenant.user:id,name')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $owed = $rows->filter(fn (ApiwaySubscription $row) => ! empty($row->meta['needs_refund']));
        $held = $rows->count() - $owed->count();

        return $this->check(
            'apiway:undelivered',
            'API Way',
            'Paid but not delivered',
            match (true) {
                $owed->isNotEmpty() => 'down',
                $held > 0 => 'warn',
                default => 'ok',
            },
            (string) $rows->count(),
            'Purchases charged on our side that ProxyBR never provisioned. Refund each one at MercadoPago, then mark it settled on the customer page — this stays red until you do.',
            [
                'awaiting_refund' => $owed->count(),
                'held_at_capacity' => $held,
                // Named, not just counted: an operator cannot act on a number.
                'rows' => $rows->map(fn (ApiwaySubscription $row) => [
                    'id' => $row->id,
                    'tenant_id' => $row->tenant_id,
                    'tenant' => $row->tenant?->user?->name,
                    'quantity' => $row->quantity,
                    'amount_cents' => $row->total_price_cents,
                    'reason' => $row->meta['failure']['code'] ?? ($row->meta['capacity_hold']['code'] ?? null),
                    'held_since' => $row->meta['capacity_hold']['since'] ?? null,
                ])->values(),
            ],
        );
    }

    /** Active mailboxes that have stopped being polled. */
    private function emailSync(): array
    {
        $active = Connection::query()
            ->where('channel', Channel::Email->value)
            ->where('status', ConnectionStatus::Active->value);

        $total = (clone $active)->count();

        if ($total === 0) {
            return $this->check('email:sync', 'Email', 'Mailbox sync', 'ok', '0', 'No active e-mail connections.');
        }

        // 30 minutes: the scheduler queues a pull every minute, so anything
        // this old is a stuck lock or a dead worker, not slow IMAP.
        $stale = (clone $active)
            ->where(fn ($q) => $q
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<', now()->subMinutes(30)))
            ->count();

        return $this->check(
            'email:sync',
            'Email',
            'Mailboxes not syncing',
            $stale > 0 ? 'warn' : 'ok',
            "{$stale} / {$total}",
            'Active mailboxes with no successful pull in the last 30 minutes.',
        );
    }

    /** Channels the tenant thinks are connected but that are failing. */
    private function brokenConnections(): array
    {
        $broken = Connection::query()
            ->whereIn('status', [ConnectionStatus::Inactive->value, ConnectionStatus::Pending->value])
            ->whereNotNull('credentials')
            ->count();

        return $this->check(
            'connections:broken',
            'Channels',
            'Disconnected channels',
            $broken > 0 ? 'warn' : 'ok',
            (string) $broken,
            'Connections that hold credentials but are not active — usually a revoked token. The customer sees an inbox that has gone quiet.',
        );
    }

    private function check(
        string $key,
        string $group,
        string $label,
        string $status,
        string $value,
        string $detail,
        array $meta = [],
    ): array {
        return compact('key', 'group', 'label', 'status', 'value', 'detail', 'meta');
    }

    /** @param list<string> $statuses */
    private function worst(array $statuses): string
    {
        foreach (['down', 'warn', 'unknown'] as $level) {
            if (in_array($level, $statuses, true)) {
                return $level;
            }
        }

        return 'ok';
    }
}
