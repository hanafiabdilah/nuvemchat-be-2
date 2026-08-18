<?php

namespace App\Support;

use App\Models\SystemHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "I ran." One line at the end of every background process that matters.
 *
 * The platform's moving parts are mostly not HTTP: the Discord gateway daemon,
 * the queue workers, the scheduler's renewals and purges. When one stops, the
 * product carries on looking healthy and the failure surfaces days later as a
 * support ticket — an API Way subscription that missed its renewal window is
 * revoked permanently by ProxyBR, and nothing in the panel ever said so.
 *
 * Each process declares an expected interval here, which is what turns a
 * timestamp into a verdict: without one, a reader is left guessing whether
 * "last ran 40 minutes ago" is fine (hourly job) or an outage (30s daemon).
 */
class Heartbeat
{
    /**
     * name => [label, expected interval in seconds, why it matters]
     *
     * A process missing from this list can still ping — it simply shows up
     * unclassified rather than being silently dropped.
     */
    public const PROCESSES = [
        'scheduler' => ['Scheduler', 120, 'Runs every scheduled command. If this is down, everything below is too.'],
        'queue:default' => ['Queue worker (default)', 300, 'Broadcasts events, downloads inbound media, sends receipts.'],
        'queue:broadcasts' => ['Broadcast worker', 300, 'Pumps campaign sends. Campaigns stall without it.'],
        'discord:gateway' => ['Discord gateway', 90, 'The only way Discord DMs arrive — Discord has no message webhook.'],
        'broadcasts:tick' => ['Broadcast watchdog', 180, 'Starts scheduled campaigns and revives stalled ones.'],
        'media:purge' => ['Media purge', 7200, 'Deletes expired media. Storage only grows without it.'],
        'apiway:renew' => ['API Way renewal', 7200, 'ProxyBR gives no grace period — a missed renewal is a permanent revoke.'],
        'apiway:sync' => ['API Way sync', 7200, 'Expires and releases instances whose subscription ended.'],
        'emails:fetch' => ['Email inbox sync', 900, 'IMAP polling. Without it, e-mail conversations stop arriving.'],
    ];

    /**
     * Record that $name is alive right now.
     *
     * Never throws: a monitoring write must not be able to take down the thing
     * it is monitoring. A failed ping degrades to "looks dead", which is the
     * safe direction to be wrong in.
     */
    public static function ping(string $name, array $meta = []): void
    {
        try {
            SystemHeartbeat::updateOrCreate(
                ['name' => $name],
                ['beat_at' => now(), 'meta' => $meta ?: null],
            );
        } catch (\Throwable $e) {
            Log::warning('Heartbeat: could not record beat', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ping at most once per $everySeconds.
     *
     * For processes that would otherwise write on every unit of work — a queue
     * worker chewing through a backlog would turn one row into thousands of
     * writes a minute, which is a strange way to pay for a liveness check.
     */
    public static function throttledPing(string $name, int $everySeconds = 60, array $meta = []): void
    {
        $key = "heartbeat:throttle:{$name}";

        if (Cache::get($key) !== null) {
            return;
        }

        Cache::put($key, true, $everySeconds);
        self::ping($name, $meta);
    }

    /** Seconds after which a process is considered late. */
    public static function expectedInterval(string $name): ?int
    {
        return self::PROCESSES[$name][1] ?? null;
    }

    /**
     * ok | late | down | unknown.
     *
     * `late` is one missed interval — normal jitter under load. `down` is three,
     * which no healthy process reaches. `unknown` means it has never pinged:
     * either it was never deployed, or it has been dead since before this table
     * existed, and those two are not distinguishable from here.
     */
    public static function verdict(?\Illuminate\Support\Carbon $beatAt, ?int $expected): string
    {
        if ($beatAt === null || $expected === null) {
            return 'unknown';
        }

        $age = $beatAt->diffInSeconds(now());

        return match (true) {
            $age <= $expected => 'ok',
            $age <= $expected * 3 => 'late',
            default => 'down',
        };
    }
}
