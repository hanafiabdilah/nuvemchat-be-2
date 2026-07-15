<?php

namespace App\Services\Notification;

use App\Enums\Notification\NotificationType;
use App\Models\WhatsappMessageLog;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates platform notifications: resolves the template for an event,
 * interpolates variables, and hands the message to the configured provider.
 *
 * BLUEPRINT: this is the single entry point notifications will flow through, but
 * nothing calls it yet. Wiring the triggers (e.g. after registration, after a
 * subscription activates, a scheduled due-date reminder) is intentionally left
 * out for now — see the callers noted in each NotificationType.
 */
class NotificationService
{
    public function __construct(
        protected NotificationProviderFactory $factory,
    ) {}

    /**
     * Send a notification for a lifecycle event.
     *
     * @param string               $to   Recipient (WhatsApp phone in E.164 for WA providers).
     * @param array<string, mixed> $vars Template variables, e.g. ['name' => 'Ana', 'plan' => 'Pro'].
     */
    public function send(NotificationType $type, string $to, array $vars = []): void
    {
        if (! NotificationConfig::enabled()) {
            return; // notifications globally disabled
        }

        if (! NotificationConfig::eventEnabled($type)) {
            return; // this specific event is toggled off
        }

        $provider = $this->factory->make();

        if (! $provider->isConfigured()) {
            Log::warning('NotificationService: provider not configured, skipping', [
                'type' => $type->value,
                'provider' => $provider->key(),
            ]);

            return;
        }

        $message = $this->render($type, $vars);

        try {
            $provider->send($to, $message);
            WhatsappMessageLog::record($provider->key(), $to, 'notification:' . $type->value, $message, WhatsappMessageLog::STATUS_SENT);
        } catch (\Throwable $th) {
            WhatsappMessageLog::record($provider->key(), $to, 'notification:' . $type->value, $message, WhatsappMessageLog::STATUS_FAILED, $th->getMessage());
            throw $th;
        }
    }

    /**
     * Interpolate {{key}} placeholders in the event's template.
     *
     * @param array<string, mixed> $vars
     */
    public function render(NotificationType $type, array $vars = []): string
    {
        // Use the admin-configured template when present, else the default.
        $message = NotificationConfig::template($type);

        foreach ($vars as $key => $value) {
            $message = str_replace('{{' . $key . '}}', (string) $value, $message);
        }

        return $message;
    }
}
