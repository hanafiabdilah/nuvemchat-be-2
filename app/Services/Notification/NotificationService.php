<?php

namespace App\Services\Notification;

use App\Enums\Notification\NotificationType;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates platform notifications: resolves the template for an event,
 * interpolates variables, and queues the message to the configured provider.
 *
 * This is the single entry point every platform → user notification flows through.
 * Triggers live at the lifecycle boundaries noted on each NotificationType:
 * OtpService (whatsapp_otp, welcome_registration), BillingService (subscription
 * activated/past_due/suspended) and billing:send-due-reminders (subscription_due).
 */
class NotificationService
{
    /**
     * Send a notification for a lifecycle event. Best-effort: never throws, so a
     * broken provider cannot take down the caller.
     *
     * @param string               $to     Recipient WhatsApp number (digits; normalized here).
     * @param array<string, mixed> $vars   Template variables, e.g. ['name' => 'Ana', 'plan' => 'Pro'].
     * @param int|null             $userId Attributed on the audit log row when known.
     * @return bool True when the message was queued; false when a toggle or a
     *              missing recipient suppressed it. Says nothing about delivery.
     */
    public function send(NotificationType $type, string $to, array $vars = [], ?int $userId = null): bool
    {
        // Required events (the OTP) ignore both switches — turning notifications off
        // must not break signup verification. This is what isRequired() has always
        // promised; enforcing it here is what lets the OTP route through this method.
        if (! $type->isRequired()) {
            if (! NotificationConfig::enabled()) {
                return false; // notifications globally disabled
            }

            if (! NotificationConfig::eventEnabled($type)) {
                return false; // this specific event is toggled off
            }
        }

        $to = OtpService::normalizeNumber($to);

        if ($to === '') {
            Log::warning('NotificationService: no recipient number, skipping', ['type' => $type->value]);

            return false;
        }

        // Off the request path, and never rethrown: a slow or dead provider must not
        // hang a web request (the bug recorded in OtpService::dispatch()) nor abort a
        // console batch mid-loop. The job resolves the provider and records the
        // attempt to whatsapp_message_logs either way.
        SendWhatsappMessageJob::dispatchAfterResponse(
            $to,
            // In the recipient's own language, resolved from the account the
            // caller already identified for the log. One extra read on a path
            // that is about to queue a network call, in exchange for never
            // again sending a Brazilian sentence to somebody who does not read
            // one.
            $this->render($type, $vars, $this->localeFor($userId)),
            $type->logType(),
            $userId,
        );

        return true;
    }

    /**
     * The language to write to this recipient in, or null to leave it to the
     * platform default.
     *
     * Resolved from the account the caller already names for the audit log
     * rather than from a new argument: every sender here has the user in hand,
     * and a locale parameter is the kind of thing the next call site forgets —
     * silently, in Portuguese, to somebody who does not read it.
     *
     * Failure is swallowed on purpose. This runs inside send(), which promises
     * never to throw, and a missing row is a reason to fall back to the default
     * language, not to lose a customer's verification code.
     */
    private function localeFor(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        try {
            return User::query()->with('tenant.market')->find($userId)?->localeCode();
        } catch (\Throwable $th) {
            Log::warning('NotificationService: could not resolve the recipient language', [
                'user_id' => $userId,
                'error' => $th->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Interpolate {{key}} placeholders in the event's template.
     *
     * @param array<string, mixed> $vars
     */
    public function render(NotificationType $type, array $vars = [], ?string $locale = null): string
    {
        // Use the admin-configured template when present, else the default.
        $message = NotificationConfig::template($type, $locale);

        foreach ($vars as $key => $value) {
            $message = str_replace('{{' . $key . '}}', (string) $value, $message);
        }

        return $message;
    }
}
