<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Broadcast\Status as BroadcastStatus;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiwaySubscription;
use App\Models\Broadcast;
use App\Models\Connection;
use App\Models\SystemHeartbeat;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Connection\ConnectionCredentials;
use App\Services\Webhook\ChatWebhookSecret;
use App\Support\Heartbeat;
use App\Support\PlatformUrl;
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
                $this->chatWebhookSecrets(),
                $this->metaWebhookSecrets(),
                $this->adminTwoFactor(),
                $this->credentialsAtRest(),
                $this->productionSettings(),
                $this->platformUrl(),
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

        // Which of those are actually in trouble. Since renewals are charged to
        // the prepaid balance they now happen on their own, so "expiring in
        // three days" is the healthy case and warning on it would leave this
        // check permanently yellow — which is the same as switched off. What
        // deserves attention is a renewal the balance will not cover.
        $atRisk = app(ApiwayService::class)->renewalsAtRisk();

        return $this->check(
            'apiway:renewals',
            'API Way',
            'Renewals that will not go through',
            match (true) {
                $alreadyOverdue > 0 => 'down',
                $atRisk->isNotEmpty() => 'warn',
                default => 'ok',
            },
            (string) ($atRisk->count() + $alreadyOverdue),
            'ProxyBR has no grace period — an instance past its expiry is revoked permanently, not suspended. These tenants do not have the balance to cover their renewal; a call before the date is the only thing that saves the number.',
            [
                'at_risk' => $atRisk->count(),
                'expiring_3d' => $expiringSoon,
                'overdue' => $alreadyOverdue,
                // Named, not counted: nobody can phone a number.
                'rows' => $atRisk->take(25)->map(fn (ApiwaySubscription $row) => [
                    'id' => $row->id,
                    'tenant_id' => $row->tenant_id,
                    'tenant' => $row->tenant?->user?->name,
                    'quantity' => $row->quantity,
                    'amount_cents' => $row->total_price_cents,
                    'expires_at' => $row->expires_at?->toDateString(),
                    'reason' => $row->source === ApiwaySubscriptionSource::PlanIncluded
                        ? 'plan_lapsed'
                        : 'insufficient_credit',
                ])->values(),
            ],
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
            'Purchases charged on our side that ProxyBR never provisioned. Refund each one at the payment service, then mark it settled on the customer page — this stays red until you do.',
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

    /**
     * Telegram and API Way connections whose inbound webhook still carries no
     * secret — deliveries to them are accepted on trust.
     *
     * This is the check that says whether WEBHOOK_CHAT_STRICT can be turned on.
     * `warn` rather than `down` while any remain: messages are still arriving,
     * which is the opposite of an outage, and the exposure is bounded to the
     * connections named here. Once strict mode is on, a connection left
     * unsecured IS an outage for that inbox, so it reads `down`.
     */
    private function chatWebhookSecrets(): array
    {
        $unsecured = Connection::query()
            ->whereIn('channel', [Channel::Telegram->value, Channel::WhatsappApiway->value])
            ->where('status', ConnectionStatus::Active->value)
            ->get(['id', 'tenant_id', 'channel', 'name', 'credentials'])
            ->filter(fn (Connection $connection) => ChatWebhookSecret::of($connection) === null);

        $strict = ChatWebhookSecret::strict();

        if ($unsecured->isEmpty()) {
            return $this->check(
                'webhooks:chat-secrets',
                'Channels',
                'Chat webhook secrets',
                'ok',
                $strict ? 'All secured (strict)' : 'All secured',
                $strict
                    ? 'Every inbound Telegram and API Way delivery has to present its connection secret.'
                    : 'Every active connection carries a secret. Set WEBHOOK_CHAT_STRICT=true to refuse deliveries from any connection that does not.',
            );
        }

        return $this->check(
            'webhooks:chat-secrets',
            'Channels',
            'Chat webhook secrets',
            $strict ? 'down' : 'warn',
            (string) $unsecured->count(),
            $strict
                ? 'Strict mode is on and these connections have no secret, so their inbound messages are being refused. Run `php artisan webhooks:secure-chat`.'
                : 'These connections accept inbound webhooks from anyone who knows their id. Run `php artisan webhooks:secure-chat`, then set WEBHOOK_CHAT_STRICT=true.',
            [
                // Named, not counted: an operator cannot act on a number, and
                // the ones that fail to secure are usually a specific offline
                // instance rather than the whole set.
                'rows' => $unsecured->take(25)->map(fn (Connection $connection) => [
                    'connection_id' => $connection->id,
                    'tenant_id' => $connection->tenant_id,
                    'channel' => $connection->channel->value,
                    'name' => $connection->name,
                ])->values()->all(),
            ],
        );
    }

    /**
     * The app secrets Meta's webhooks are verified against.
     *
     * Signature verification refuses when the secret is missing, so an empty
     * one is not a weakness any more — it is an outage, and a silent one:
     * WhatsApp, Instagram or Messenger simply stop delivering. That is the
     * right failure mode and exactly why it has to be visible here.
     *
     * Only counted against channels the platform actually runs. A workspace
     * with no Instagram connection does not need an Instagram secret, and a
     * check that stays red for a channel nobody uses teaches operators to
     * ignore this page.
     */
    private function metaWebhookSecrets(): array
    {
        $inUse = Connection::query()
            ->where('status', ConnectionStatus::Active->value)
            ->whereIn('channel', [
                Channel::WhatsappOfficial->value,
                Channel::Messenger->value,
                Channel::Instagram->value,
            ])
            ->pluck('channel')
            ->map(fn ($channel) => $channel instanceof Channel ? $channel->value : (string) $channel)
            ->unique();

        $missing = [];

        $needsFacebook = $inUse->contains(Channel::WhatsappOfficial->value)
            || $inUse->contains(Channel::Messenger->value);

        if ($needsFacebook && empty(\App\Services\Connection\Meta\FacebookConfig::appSecret())) {
            $missing[] = 'facebook.app_secret (WhatsApp Official, Messenger)';
        }

        if ($inUse->contains(Channel::Instagram->value)
            && empty(\App\Services\Connection\Meta\InstagramConfig::clientSecret())) {
            $missing[] = 'instagram.client_secret';
        }

        if ($missing === []) {
            return $this->check(
                'webhooks:meta',
                'Channels',
                'Meta webhook secrets',
                'ok',
                $inUse->isEmpty() ? 'No Meta channels' : 'Configured',
                'Inbound WhatsApp, Instagram and Messenger deliveries are checked against the app secret before anything is read from them.',
            );
        }

        return $this->check(
            'webhooks:meta',
            'Channels',
            'Meta webhook secrets',
            'down',
            (string) count($missing),
            'Signature verification refuses without a secret, so these channels are not receiving messages at all. Set them in Integrations.',
            ['rows' => $missing],
        );
    }

    /**
     * Back Office accounts with no second factor enrolled.
     *
     * This is the check that says whether ADMIN_MFA_REQUIRED can be turned on.
     * `warn` while any remain and the flag is off — they can still sign in, and
     * the exposure is bounded to the accounts named here. Once the flag is on,
     * an account left unenrolled cannot work at all, so it reads `down`.
     */
    private function adminTwoFactor(): array
    {
        $twoFactor = app(\App\Services\Auth\TwoFactor::class);
        $required = (bool) config('services.admin.mfa_required', false);

        $admins = \App\Models\Admin::query()->get(['id', 'name', 'email', 'two_factor_secret', 'two_factor_confirmed_at']);

        // Only accounts that can actually use the Back Office count. One
        // stripped of every platform role grants nothing, so it is not an
        // exposure and would only make this number impossible to get to zero.
        $exposed = $admins
            ->filter(fn ($admin) => $admin->isPlatformAdmin())
            ->reject(fn ($admin) => $twoFactor->isEnabled($admin));

        if ($exposed->isEmpty()) {
            return $this->check(
                'admin:two-factor',
                'Platform',
                'Back Office two-factor',
                'ok',
                $required ? 'All enrolled (enforced)' : 'All enrolled',
                $required
                    ? 'Every Back Office account needs a second factor to sign in.'
                    : 'Every account has enrolled. Set ADMIN_MFA_REQUIRED=true to refuse any that has not.',
            );
        }

        return $this->check(
            'admin:two-factor',
            'Platform',
            'Back Office two-factor',
            $required ? 'down' : 'warn',
            (string) $exposed->count(),
            $required
                ? 'Enforcement is on and these accounts have no second factor, so they cannot sign in. They must enrol from Settings.'
                : 'These accounts reach every workspace on the platform with a password alone. Have them enrol from Settings, then set ADMIN_MFA_REQUIRED=true.',
            [
                'rows' => $exposed->take(25)->map(fn ($admin) => [
                    'admin_id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                ])->values()->all(),
            ],
        );
    }

    /**
     * The address webhooks and OAuth callbacks are registered on.
     *
     * Unset is ok, not warn: with one domain the request host *is* the
     * platform host, and a check that stays yellow until a country launches
     * teaches operators to ignore this page. What is worth a warning is a value
     * that isn't doing what it looks like it does.
     */
    /**
     * Channel secrets still sitting in plaintext in `connections.credentials`.
     *
     * The column holds the token that sends WhatsApp messages as a customer's
     * business and the bot token that is total control of their Telegram bot,
     * and it is the part of the database that gets copied around — into a
     * backup, onto a laptop, into staging. Encryption is applied by the cast
     * from now on; this counts the rows written before that and never
     * re-saved, which `connections:encrypt-credentials` finishes.
     *
     * ⚠️ `warn`, never `down`: nothing is broken while this is non-zero. A
     * plaintext row works exactly as it always did — the exposure is in the
     * backup, not in the running platform — and calling that an outage would
     * teach operators that red on this page does not mean red.
     */
    private function credentialsAtRest(): array
    {
        $plaintext = Connection::query()
            ->whereNotNull('credentials')
            ->get(['id', 'name', 'channel', 'credentials'])
            ->filter(fn (Connection $connection) => ConnectionCredentials::needsProtecting(
                json_decode((string) $connection->getRawOriginal('credentials'), true),
            ));

        if ($plaintext->isEmpty()) {
            return $this->check(
                'connections:credentials-at-rest',
                'Platform',
                'Channel secrets at rest',
                'ok',
                'Encrypted',
                'Every stored channel credential is encrypted, so a database backup no longer carries them in the clear.',
            );
        }

        return $this->check(
            'connections:credentials-at-rest',
            'Platform',
            'Channel secrets at rest',
            'warn',
            (string) $plaintext->count(),
            'These connections still hold their channel tokens in plaintext. Nothing is broken — they work as they always did — but a database backup carries them readable. Run `php artisan connections:encrypt-credentials`.',
            [
                'rows' => $plaintext->take(25)->map(fn (Connection $connection) => [
                    'connection_id' => $connection->id,
                    'channel' => $connection->channel?->value,
                    'name' => $connection->name,
                ])->values()->all(),
            ],
        );
    }

    /**
     * Two settings that are harmless everywhere except here.
     *
     * ⚠️ `APP_DEBUG=true` in production is not a verbosity setting — it is a
     * disclosure. Laravel's debug page prints the stack, the query that failed
     * with its bindings, and the whole environment: database password, app key,
     * every channel credential, the payment-service key. Anyone who can make
     * this application throw can read them, and making a web application throw
     * is not hard. It is the single highest-value misconfiguration on the box,
     * so it reads `down` rather than `warn`.
     *
     * `LOG_LEVEL=debug` is a smaller version of the same problem and easy to
     * arrive at by accident, since the config default is `debug`: the inbound
     * message paths log full webhook payloads at that level (see ChatService
     * and WhatsAppController), which puts customer message bodies and phone
     * numbers into a file the Back Office can download.
     *
     * Both are read from live config rather than from the file on disk — what
     * matters is what this process booted with, and env files are edited on the
     * server without anything here noticing.
     */
    private function productionSettings(): array
    {
        $env = (string) config('app.env');
        $production = $env === 'production';
        $debug = (bool) config('app.debug');
        $logLevel = strtolower((string) config('logging.channels.'.config('logging.default').'.level', 'debug'));

        if ($production && $debug) {
            return $this->check(
                'platform:debug',
                'Platform',
                'Debug mode',
                'down',
                'APP_DEBUG=true',
                'Any unhandled error prints the environment — database password, app key, channel and payment credentials — to whoever triggered it. Set APP_DEBUG=false and restart the PHP containers.',
                ['env' => $env, 'log_level' => $logLevel],
            );
        }

        if ($production && $logLevel === 'debug') {
            return $this->check(
                'platform:debug',
                'Platform',
                'Debug mode',
                'warn',
                'LOG_LEVEL=debug',
                'Debug mode is off, but the log level still records full webhook payloads — customer message bodies and phone numbers — in a file this panel can download. Set LOG_LEVEL=info.',
                ['env' => $env, 'log_level' => $logLevel],
            );
        }

        if (! $production) {
            // Quiet rather than yellow. Local and staging installs are meant to
            // run with debug on, and a permanent warning on every one of them is
            // how this page gets ignored on the box where it matters — the same
            // reason a process that has never checked in reads `unknown`.
            return $this->check(
                'platform:debug',
                'Platform',
                'Debug mode',
                'ok',
                "APP_ENV={$env}",
                'Only checked on a production install; debug output is expected here.',
                ['env' => $env, 'log_level' => $logLevel],
            );
        }

        return $this->check(
            'platform:debug',
            'Platform',
            'Debug mode',
            'ok',
            'Off',
            'Errors are not shown to callers and the log level is not recording payloads.',
            ['env' => $env, 'log_level' => $logLevel],
        );
    }

    private function platformUrl(): array
    {
        if (! PlatformUrl::isConfigured()) {
            return $this->check(
                'platform:url',
                'Platform',
                'Platform address',
                'ok',
                'Not set',
                'PLATFORM_URL is empty, so webhook and OAuth addresses follow whichever domain a request arrived on. Harmless with a single domain; set it before a second domain goes live.',
            );
        }

        $host = PlatformUrl::host();

        if ($host === null) {
            return $this->check(
                'platform:url',
                'Platform',
                'Platform address',
                'warn',
                'Invalid',
                'PLATFORM_URL must be a scheme and host only, such as https://chat.pingly.com.br. While it is unusable, addresses follow the request domain.',
            );
        }

        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if (strcasecmp($host, $appHost) !== 0) {
            return $this->check(
                'platform:url',
                'Platform',
                'Platform address',
                'warn',
                $host,
                "APP_URL points at {$appHost}. Public file links and a few integrations still read APP_URL, so the platform hands out two different addresses.",
                ['app_host' => $appHost],
            );
        }

        return $this->check(
            'platform:url',
            'Platform',
            'Platform address',
            'ok',
            $host,
            'Webhooks, OAuth callbacks and signed links are issued on this address, whichever domain the request came from.',
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
