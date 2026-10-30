<?php

namespace App\Services\Connection\Apiway;

use App\Enums\Connection\Channel;
use App\Models\ApiwayQueuedSend;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\User;
use App\Services\Connection\Proxy\ApiwayConfig;
use App\Services\Message\ApiwaySendQueue;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use App\Support\Errors\UpstreamServiceException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * The anti-ban send policy of the API Way instance behind a connection, read
 * and changed from Pingly (`/v1/instance/send-policy` + the queue routes on
 * the core).
 *
 * Every number gets the core's defaults — 13 a minute, a 3–8 s gap, 800 a
 * day — and nobody had ever changed one: a funnel that outgrew 800 messages
 * stopped answering at 08:21 and held everything until 00:00 UTC. The owner
 * of the number may trade that protection for volume; this is where they do.
 *
 * The core documents the fields but not every edge of them, and it cannot be
 * asked. Where its documentation leaves a value open, the value sent is the
 * one that cannot go wrong in either reading:
 *
 *  - "No daily limit" is UNLIMITED_DAILY_CAP, never 0. `daily_cap` is
 *    documented as `minimum: 0` with no word on what 0 means — "no limit"
 *    and "nothing a day" are both plausible, and the second silences a number.
 *  - The queue is never switched off from here. With it on, a send past any
 *    limit waits; with it off, the core refuses past 60 a minute (429), and
 *    whether the daily cap still applies then is not documented — either way
 *    a refused send is a lost message. `queue_enabled` is only ever sent as
 *    `true`, to undo a switch made somewhere else.
 *  - The gap between messages never goes below MIN_DELAY_MS. 0 is documented
 *    as allowed, but not what it means; a 1 s gap is the 60-a-minute ceiling
 *    the core enforces anyway, so nothing is lost by stopping there.
 *  - What the core stored is read back after every change and compared with
 *    what was asked, so a value it silently clamped or ignored is reported
 *    rather than assumed.
 *  - Whether messages already held for tomorrow are rescheduled by a raised
 *    cap is not documented either, so the queue follower is told to look at
 *    them again instead of trusting the time it read before the change.
 */
final class ApiwaySendPolicy
{
    /**
     * "No daily limit". Beyond reach: 60 a minute for a whole day is 86 400.
     */
    public const UNLIMITED_DAILY_CAP = 999999;

    /** The core's stability ceiling, per instance, queue or no queue. */
    public const MAX_MSGS_PER_MIN = 60;

    /** See the class note on the gap between messages. */
    public const MIN_DELAY_MS = 1000;

    /** A gap longer than this is a typo, not a policy. */
    public const MAX_DELAY_MS = 120000;

    /** The core's documented safe default ("padrao_seguro"). */
    public const DEFAULTS = [
        'msgs_per_min' => 13,
        'min_delay_ms' => 3000,
        'max_delay_ms' => 8000,
        'daily_cap' => 800,
        'auto_throttle_enabled' => true,
    ];

    /**
     * Five steps from as far as Pingly goes back to the core's default, so
     * the owner picks a level instead of tuning four numbers. Ordered fewest
     * limits first: "no limits" is what a number linked from the connection
     * wizard gets unless the owner picks otherwise, so it leads the list.
     *
     * The gap is set to match the pace (60 ÷ messages a minute, as a range
     * around it): a gap wider than that would quietly cap the pace below what
     * the level promises. Every level but "no limits" keeps the automatic
     * slow-down at high risk — the one protection that reacts to how the
     * number is actually being received, rather than to a count.
     */
    public const PRESETS = [
        'unlimited' => [
            'msgs_per_min' => self::MAX_MSGS_PER_MIN,
            'min_delay_ms' => self::MIN_DELAY_MS,
            'max_delay_ms' => self::MIN_DELAY_MS,
            'daily_cap' => self::UNLIMITED_DAILY_CAP,
            'auto_throttle_enabled' => false,
        ],
        'very_high' => [
            'msgs_per_min' => 45,
            'min_delay_ms' => 1000,
            'max_delay_ms' => 1500,
            'daily_cap' => 6000,
            'auto_throttle_enabled' => true,
        ],
        'high' => [
            'msgs_per_min' => 30,
            'min_delay_ms' => 1500,
            'max_delay_ms' => 2500,
            'daily_cap' => 3000,
            'auto_throttle_enabled' => true,
        ],
        'moderate' => [
            'msgs_per_min' => 20,
            'min_delay_ms' => 2000,
            'max_delay_ms' => 4000,
            'daily_cap' => 1500,
            'auto_throttle_enabled' => true,
        ],
        'default' => self::DEFAULTS,
    ];

    /**
     * The levels as a list, for the screens that draw them.
     *
     * @return list<array<string, int|bool|string>>
     */
    public static function presets(): array
    {
        return array_map(
            fn (string $key) => ['key' => $key] + self::PRESETS[$key],
            array_keys(self::PRESETS),
        );
    }

    /**
     * The changes one level stands for, in the shape update() takes. "No
     * daily limit" goes through the same flag as the form's checkbox, so it
     * is spelled one way on the wire whichever screen asked for it.
     *
     * @return array<string, int|bool>
     */
    public static function presetChanges(string $key): array
    {
        $values = self::PRESETS[$key];

        if ($values['daily_cap'] >= self::UNLIMITED_DAILY_CAP) {
            unset($values['daily_cap']);
            $values['daily_cap_unlimited'] = true;
        }

        return $values;
    }

    /** The level a policy is exactly at, or null for a hand-tuned one. */
    public static function presetOf(array $policy): ?string
    {
        foreach (self::PRESETS as $key => $values) {
            $matches = true;

            foreach ($values as $field => $value) {
                $current = $policy[$field] ?? null;

                // Any cap past "unlimited" reads as unlimited.
                if ($field === 'daily_cap' && $value >= self::UNLIMITED_DAILY_CAP) {
                    $matches = $matches && is_int($current) && $current >= self::UNLIMITED_DAILY_CAP;

                    continue;
                }

                $matches = $matches && $current === $value;
            }

            if ($matches) {
                return $key;
            }
        }

        return null;
    }

    /** The policy fields Pingly reads and writes; the core may return more. */
    private const FIELDS = [
        'queue_enabled',
        'msgs_per_min',
        'min_delay_ms',
        'max_delay_ms',
        'daily_cap',
        'typing_enabled',
        'auto_throttle_enabled',
    ];

    /**
     * Everything the panel shows: the policy, the risk the core scores the
     * number at, today's count against the cap, and what is waiting.
     *
     * Only the policy is required. Usage and the queue are extras read from
     * other routes, and a panel that failed because one of them did would
     * hide the setting the owner came to change.
     *
     * @return array<string, mixed>
     */
    public function show(Connection $channelConnection): array
    {
        $raw = $this->readPolicy($channelConnection);
        $policy = $this->policyFrom($raw);

        return [
            'policy' => $policy,
            'preset' => self::presetOf($policy),
            'effective_msgs_per_min' => $this->intOrNull($raw['effective_msgs_per_min'] ?? null),
            'risk' => [
                'level' => is_string($raw['risk_level'] ?? null) ? $raw['risk_level'] : null,
                'score' => $this->intOrNull($raw['risk_score'] ?? null),
            ],
            'limits' => [
                'max_msgs_per_min' => $this->intOrNull($raw['limits']['max_msgs_per_min'] ?? null) ?? self::MAX_MSGS_PER_MIN,
                'high_risk_msgs_per_min' => $this->intOrNull($raw['limits']['high_risk_msgs_per_min'] ?? null),
                'unlimited_daily_cap' => self::UNLIMITED_DAILY_CAP,
                'min_delay_ms' => self::MIN_DELAY_MS,
                'max_delay_ms' => self::MAX_DELAY_MS,
            ],
            'defaults' => self::DEFAULTS,
            'presets' => self::presets(),
            'today' => $this->today($channelConnection),
            'queue' => $this->queueSummary($channelConnection),
        ];
    }

    /**
     * Apply the owner's changes and answer with what the core actually holds.
     *
     * @param  array<string, mixed>  $changes  Validated by the controller; only
     *                                         the keys present are changed.
     * @return array<string, mixed>
     */
    public function update(Connection $channelConnection, array $changes, User $actor): array
    {
        $before = $this->policyFrom($this->readPolicy($channelConnection));
        $payload = $this->payloadFrom($changes, $before);

        if ($payload === []) {
            return $this->show($channelConnection) + ['not_applied' => []];
        }

        $response = $this->request($channelConnection, $actor)
            ->put($this->url('/v1/instance/send-policy'), $payload);

        $this->ensureSuccessful($response, $channelConnection, 'send-policy.update');

        // Held messages may have been moved by the change; see the class note.
        ApiwayQueuedSend::query()
            ->where('connection_id', $channelConnection->id)
            ->update(['check_after' => null]);

        $state = $this->show($channelConnection);
        $after = $state['policy'];

        $notApplied = array_values(array_filter(
            array_keys($payload),
            fn (string $field) => array_key_exists($field, $after) && $after[$field] !== $payload[$field],
        ));

        AuditLog::record(
            'connection.apiway_send_policy_updated',
            "API Way send policy of connection #{$channelConnection->id}",
            array_filter([
                'tenant_id' => $channelConnection->tenant_id,
                'connection_id' => $channelConnection->id,
                'requested' => $payload,
                'before' => array_intersect_key($before, $payload),
                'after' => array_intersect_key($after, $payload),
                'not_applied' => $notApplied ?: null,
            ], fn ($value) => $value !== null),
            $actor,
        );

        return $state + ['not_applied' => $notApplied];
    }

    /**
     * Remove every message the core still holds for this number.
     *
     * The core's answer is a count, not a list, so the queue is read before
     * and after: what was there and is gone was either removed or went out in
     * between. The send log decides which — the queue follower runs first —
     * and only what it still does not report as sent is marked as removed.
     *
     * @return array{deleted: int|null}
     */
    public function clearQueue(Connection $channelConnection, User $actor, ApiwaySendQueue $queue): array
    {
        $held = $this->queuedIds($channelConnection);

        $response = $this->request($channelConnection, $actor)
            ->delete($this->url('/v1/instance/quere/clear-quere'));

        $this->ensureSuccessful($response, $channelConnection, 'queue.clear');

        $deleted = $this->intOrNull($response->json('data.deleted'));
        $stillHeld = $this->queuedIds($channelConnection) ?? [];
        $removed = $held === null ? [] : array_values(array_diff($held, $stillHeld));

        $rows = ApiwayQueuedSend::query()
            ->where('connection_id', $channelConnection->id)
            ->with('message')
            ->get();

        if ($rows->isNotEmpty()) {
            $queue->sync($channelConnection, $rows);
            $queue->markRemoved(
                ApiwayQueuedSend::query()
                    ->where('connection_id', $channelConnection->id)
                    ->whereIn('queue_id', $removed)
                    ->with('message')
                    ->get(),
            );
        }

        AuditLog::record(
            'connection.apiway_send_queue_cleared',
            "API Way send queue of connection #{$channelConnection->id}",
            [
                'tenant_id' => $channelConnection->tenant_id,
                'connection_id' => $channelConnection->id,
                'deleted' => $deleted,
            ],
            $actor,
        );

        return ['deleted' => $deleted];
    }

    /**
     * The validated changes as the core's fields, with the safe readings
     * applied and the gap kept in order against what is already stored.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $current
     * @return array<string, int|bool>
     */
    private function payloadFrom(array $changes, array $current): array
    {
        $payload = [];

        if (($changes['daily_cap_unlimited'] ?? false) === true) {
            $payload['daily_cap'] = self::UNLIMITED_DAILY_CAP;
        } elseif (array_key_exists('daily_cap', $changes)) {
            $payload['daily_cap'] = (int) $changes['daily_cap'];
        }

        foreach (['msgs_per_min', 'min_delay_ms', 'max_delay_ms'] as $field) {
            if (array_key_exists($field, $changes)) {
                $payload[$field] = (int) $changes[$field];
            }
        }

        if (array_key_exists('auto_throttle_enabled', $changes)) {
            $payload['auto_throttle_enabled'] = (bool) $changes['auto_throttle_enabled'];
        }

        // Only ever on; see the class note.
        if (($changes['queue_enabled'] ?? false) === true) {
            $payload['queue_enabled'] = true;
        }

        $min = $payload['min_delay_ms'] ?? $current['min_delay_ms'] ?? null;
        $max = $payload['max_delay_ms'] ?? $current['max_delay_ms'] ?? null;

        if (is_int($min) && is_int($max) && $max < $min) {
            throw ValidationException::withMessages([
                'max_delay_ms' => 'O intervalo máximo não pode ser menor que o mínimo.',
            ]);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function readPolicy(Connection $channelConnection): array
    {
        $response = $this->request($channelConnection)->get($this->url('/v1/instance/send-policy'));

        $this->ensureSuccessful($response, $channelConnection, 'send-policy.read');

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    /** @return array<string, int|bool|null> */
    private function policyFrom(array $raw): array
    {
        $policy = [];

        foreach (self::FIELDS as $field) {
            $value = $raw[$field] ?? null;
            $policy[$field] = is_bool($value) ? $value : $this->intOrNull($value);
        }

        return $policy;
    }

    /**
     * Sent so far today against the cap. The core counts its day in UTC — a
     * send held by the cap is scheduled for 00:00 UTC — so that is the day
     * used here, and when it ends is given rather than "tomorrow".
     *
     * @return array{sent: int|null, resets_at: string}|null
     */
    private function today(Connection $channelConnection): ?array
    {
        $start = Carbon::now('UTC')->startOfDay();

        try {
            $response = $this->request($channelConnection)->get($this->url('/v1/instance/send-stats'), [
                'from' => $start->format('Y-m-d\TH:i:s\Z'),
                'to' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
                'tz' => 'UTC',
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return [
            'sent' => $this->intOrNull($response->json('data.totals.sent')),
            'resets_at' => $start->copy()->addDay()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return array{count: int, next_at: string|null}|null */
    private function queueSummary(Connection $channelConnection): ?array
    {
        $entries = $this->queuedEntries($channelConnection);

        if ($entries === null) {
            return null;
        }

        $next = collect($entries)->pluck('scheduled_at')->filter(fn ($at) => is_string($at) && $at !== '')->sort()->first();

        return ['count' => count($entries), 'next_at' => $next];
    }

    /** @return list<string>|null */
    private function queuedIds(Connection $channelConnection): ?array
    {
        $entries = $this->queuedEntries($channelConnection);

        return $entries === null
            ? null
            : array_values(array_filter(array_map(fn ($entry) => $entry['id'] ?? null, $entries), 'is_string'));
    }

    /** @return list<array<string, mixed>>|null */
    private function queuedEntries(Connection $channelConnection): ?array
    {
        try {
            $response = $this->request($channelConnection)->get($this->url('/v1/instance/quere'));
        } catch (\Throwable) {
            return null;
        }

        $data = $response->successful() ? $response->json('data') : null;

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : null;
    }

    private function request(Connection $channelConnection, ?User $actor = null): PendingRequest
    {
        $credentials = $channelConnection->credentials ?? [];

        if ($channelConnection->channel !== Channel::WhatsappApiway
            || blank($credentials['token'] ?? null)
            || blank($credentials['instance_id'] ?? null)) {
            throw ValidationException::withMessages([
                'connection' => 'Esta conexão não tem uma instância do API Way vinculada.',
            ]);
        }

        $headers = ['X-ApiWay-Source' => 'pingly'];

        // Informative on the core's side (its policy history shows it), and
        // the only way the number's owner can tell a change made here from
        // one made in another panel.
        if ($actor !== null) {
            $headers['X-ApiWay-Actor'] = "user:{$actor->id}";
        }

        return Http::withToken($credentials['token'])
            ->withHeaders($headers)
            ->withQueryParameters(['instanceId' => $credentials['instance_id']])
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(20);
    }

    private function url(string $path): string
    {
        return ApiwayConfig::baseUrl() . $path;
    }

    /** @throws UpstreamServiceException */
    private function ensureSuccessful(Response $response, Connection $channelConnection, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $error = $response->json('error');
        $message = $response->json('message');

        throw UpstreamError::exception(
            UpstreamProvider::ApiwayCore,
            is_string($message) ? $message : mb_substr($response->body(), 0, 500),
            is_string($error) ? $error : null,
            $response->status() >= 500 ? 502 : 422,
            ['operation' => $operation, 'connection_id' => $channelConnection->id],
            details: UpstreamError::httpDetails($response),
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_numeric($value) && (string) (int) $value === (string) $value)
            ? (int) $value
            : null;
    }
}
