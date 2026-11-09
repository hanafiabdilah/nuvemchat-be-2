<?php

namespace App\Services\Notification;

use App\Enums\Notification\NotificationType;
use App\Jobs\SendFlowFailureAlert;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Contact\ContactIdentity;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Operational alerts sent to a person's own WhatsApp: a number went offline, a
 * funnel stopped because messages could not be sent, a receipt the AI read, how
 * many leads came in.
 *
 * Opt-in per person and per alert (`notification_preferences.whatsapp_alerts`),
 * delivered to the account's verified WhatsApp number through NotificationService
 * — so the wording is the admin-editable template of each NotificationType, in
 * the recipient's language, and every send lands in the WhatsApp log.
 *
 * Recipients are narrowed to the connections a person can reach: an agent who
 * was given one inbox is not told about the others.
 *
 * Every entry point here is best-effort and never throws. They run inside
 * flows, webhooks and status checks, and an alert that cannot be sent is not a
 * reason for any of those to fail.
 */
final class WhatsappAlerts
{
    public const CONNECTION_DOWN = 'connection_down';

    public const FLOW_FAILED = 'flow_failed';

    public const RECEIPT_APPROVED = 'receipt_approved';

    public const RECEIPT_REJECTED = 'receipt_rejected';

    public const LEAD_SUMMARY = 'lead_summary';

    /** Ad spend, leads, sales and ROAS — needs a connected Meta ad account. */
    public const ADS_SUMMARY = 'ads_summary';

    public const SUMMARY_WEEKLY = 'weekly';

    /** Daily for the day before, or on Mondays for the week before. */
    public const ADS_SUMMARY_MODES = [self::SUMMARY_OFF, self::SUMMARY_DAILY, self::SUMMARY_WEEKLY];

    /** The on/off alerts. */
    public const TOGGLES = [self::CONNECTION_DOWN, self::FLOW_FAILED, self::RECEIPT_APPROVED, self::RECEIPT_REJECTED];

    public const SUMMARY_OFF = 'off';

    public const SUMMARY_HOURLY = 'hourly';

    public const SUMMARY_DAILY = 'daily';

    public const SUMMARY_MODES = [self::SUMMARY_OFF, self::SUMMARY_HOURLY, self::SUMMARY_DAILY];

    /** The local hour the daily summary goes out, covering the day before. */
    public const DAILY_SUMMARY_HOUR = 8;

    /**
     * How long failed sends are gathered before one message reports them.
     * A channel that dies takes every conversation in flight with it, and one
     * message per conversation would be a flood about a single problem.
     */
    public const FLOW_FAILURE_WINDOW_SECONDS = 300;

    /** The same connection is reported down at most this often. */
    private const CONNECTION_DOWN_COOLDOWN_SECONDS = 1800;

    /**
     * A person's choices, filled in. Everything is off until they turn it on:
     * these go to a phone, and nobody signed up for messages they never asked for.
     *
     * @param  array<string, mixed>  $stored
     * @return array{connection_down: bool, flow_failed: bool, receipt_approved: bool, receipt_rejected: bool, lead_summary: string, ads_summary: string}
     */
    public static function settings(array $stored): array
    {
        $settings = [];

        foreach (self::TOGGLES as $alert) {
            $settings[$alert] = (bool) ($stored[$alert] ?? false);
        }

        $mode = $stored[self::LEAD_SUMMARY] ?? self::SUMMARY_OFF;
        $settings[self::LEAD_SUMMARY] = in_array($mode, self::SUMMARY_MODES, true) ? $mode : self::SUMMARY_OFF;

        $mode = $stored[self::ADS_SUMMARY] ?? self::SUMMARY_OFF;
        $settings[self::ADS_SUMMARY] = in_array($mode, self::ADS_SUMMARY_MODES, true) ? $mode : self::SUMMARY_OFF;

        return $settings;
    }

    /** Whether this person wants this alert and has a number it can go to. */
    public static function wants(User $user, string $alert): bool
    {
        if ($user->whatsapp_verified_at === null || trim((string) $user->whatsapp_number) === '') {
            return false;
        }

        $value = $user->notificationSettings()['whatsapp_alerts'][$alert] ?? false;

        return in_array($alert, [self::LEAD_SUMMARY, self::ADS_SUMMARY], true) ? $value !== self::SUMMARY_OFF : $value === true;
    }

    /**
     * Who in this workspace gets this alert about this connection.
     *
     * @return Collection<int, User>
     */
    public static function recipients(int $tenantId, string $alert, ?Connection $connection = null): Collection
    {
        return User::where('tenant_id', $tenantId)
            ->whereNotNull('whatsapp_verified_at')
            ->whereNotNull('whatsapp_number')
            ->whereNotNull('notification_preferences')
            ->get()
            ->filter(fn (User $user) => self::wants($user, $alert)
                && ($connection === null || $user->canAccessConnection($connection)))
            ->values();
    }

    /**
     * A connection stopped working without anyone here asking it to — logged
     * out from the phone, a revoked token, the business app disconnecting us.
     *
     * @param  string  $reason  a key of alerts.connection_reasons
     */
    public static function connectionDown(Connection $connection, string $reason): void
    {
        self::safely('connection down', function () use ($connection, $reason) {
            if (! Cache::add("alert:connection-down:{$connection->id}", true, self::CONNECTION_DOWN_COOLDOWN_SECONDS)) {
                return;
            }

            foreach (self::recipients((int) $connection->tenant_id, self::CONNECTION_DOWN, $connection) as $user) {
                $locale = $user->localeCode();

                self::send(NotificationType::AlertConnectionDown, $user, [
                    'connection' => $connection->name,
                    'reason' => __('alerts.connection_reasons.'.$reason, [], $locale),
                ]);
            }
        });
    }

    /**
     * A flow stopped because one of its messages could not be delivered.
     * Gathered per connection and reported once per window (SendFlowFailureAlert).
     */
    public static function flowFailed(Conversation $conversation, ?string $flowName, ?string $reason): void
    {
        self::safely('flow failed', function () use ($conversation, $flowName, $reason) {
            $connection = $conversation->connection;

            if (! $connection || self::recipients((int) $connection->tenant_id, self::FLOW_FAILED, $connection)->isEmpty()) {
                return;
            }

            $key = self::flowFailureKey($connection->id);

            Cache::lock("{$key}:lock", 10)->block(5, function () use ($key, $flowName, $reason) {
                $batch = Cache::get($key, ['count' => 0, 'flows' => [], 'reason' => null]);
                $batch['count']++;

                if ($flowName && ! in_array($flowName, $batch['flows'], true)) {
                    $batch['flows'][] = $flowName;
                }

                $batch['reason'] = $reason ?: $batch['reason'];

                Cache::put($key, $batch, now()->addHour());
            });

            if (Cache::add("{$key}:scheduled", true, self::FLOW_FAILURE_WINDOW_SECONDS + 60)) {
                SendFlowFailureAlert::dispatch($connection->id)->delay(now()->addSeconds(self::FLOW_FAILURE_WINDOW_SECONDS));
            }
        });
    }

    /** Report the failures gathered for this connection, and start a new window. */
    public static function sendFlowFailures(int $connectionId): void
    {
        $key = self::flowFailureKey($connectionId);
        $batch = Cache::pull($key);
        Cache::forget("{$key}:scheduled");

        $connection = Connection::find($connectionId);

        if (! is_array($batch) || ($batch['count'] ?? 0) < 1 || ! $connection) {
            return;
        }

        foreach (self::recipients((int) $connection->tenant_id, self::FLOW_FAILED, $connection) as $user) {
            $locale = $user->localeCode();

            self::send(NotificationType::AlertFlowFailed, $user, [
                'count' => (string) $batch['count'],
                'flow' => $batch['flows'] !== [] ? implode(', ', array_slice($batch['flows'], 0, 3)) : __('alerts.unnamed_flow', [], $locale),
                'connection' => $connection->name,
                'reason' => $batch['reason'] ?: __('alerts.unknown_reason', [], $locale),
            ]);
        }
    }

    /** The AI read a payment receipt and accepted it: a sale. */
    public static function receiptApproved(Conversation $conversation, int $amountCents, ?string $currency): void
    {
        self::safely('receipt approved', function () use ($conversation, $amountCents, $currency) {
            $connection = $conversation->connection;

            if (! $connection) {
                return;
            }

            foreach (self::recipients((int) $connection->tenant_id, self::RECEIPT_APPROVED, $connection) as $user) {
                self::send(NotificationType::AlertReceiptApproved, $user, [
                    'contact' => self::contactLabel($conversation),
                    'amount' => Money::format($amountCents, $currency),
                    'connection' => $connection->name,
                ]);
            }
        });
    }

    /** The AI read a payment receipt and refused it, for the last time. */
    public static function receiptRejected(Conversation $conversation, ?string $reason): void
    {
        self::safely('receipt rejected', function () use ($conversation, $reason) {
            $connection = $conversation->connection;

            if (! $connection) {
                return;
            }

            foreach (self::recipients((int) $connection->tenant_id, self::RECEIPT_REJECTED, $connection) as $user) {
                $locale = $user->localeCode();
                $key = 'alerts.receipt_reasons.'.($reason ?: 'unknown');

                self::send(NotificationType::AlertReceiptRejected, $user, [
                    'contact' => self::contactLabel($conversation),
                    'reason' => __($key, [], $locale) !== $key ? __($key, [], $locale) : __('alerts.receipt_reasons.unknown', [], $locale),
                    'connection' => $connection->name,
                ]);
            }
        });
    }

    /** A first message, so a person can see alerts reach this number before relying on them. */
    public static function test(User $user): bool
    {
        return app(NotificationService::class)->send(NotificationType::AlertTest, (string) $user->whatsapp_number, [
            'name' => $user->name,
        ], $user->id);
    }

    /** @param  array<string, string>  $vars */
    public static function send(NotificationType $type, User $user, array $vars): bool
    {
        return app(NotificationService::class)->send($type, (string) $user->whatsapp_number, ['name' => $user->name] + $vars, $user->id);
    }

    /** The customer as a person would recognise them: their name, else their number. */
    private static function contactLabel(Conversation $conversation): string
    {
        $contact = $conversation->contact;

        return ContactIdentity::name($contact)
            ?? (ContactIdentity::for($contact)['phone'] ?? null)
            ?? (string) ($contact?->name ?: $conversation->external_id);
    }

    private static function flowFailureKey(int $connectionId): string
    {
        return "alert:flow-failed:{$connectionId}";
    }

    private static function safely(string $what, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $th) {
            Log::warning("WhatsappAlerts: could not send the {$what} alert", ['error' => $th->getMessage()]);
        }
    }
}
