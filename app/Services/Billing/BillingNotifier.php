<?php

namespace App\Services\Billing;

use App\Enums\Notification\NotificationType;
use App\Models\Subscription;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the template variables for subscription notifications and resolves
 * the recipient. Shared by BillingService (status transitions) and the
 * billing:send-due-reminders command, so it does not live on either.
 */
class BillingNotifier
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    /**
     * Notify the tenant owner about a subscription event. Silently skipped when
     * the owner has no verified WhatsApp number — a missing notification must
     * never stop a subscription from being activated or suspended.
     *
     * @return bool True when the message was queued; false when the owner is
     *              unreachable or a notification toggle suppressed it.
     */
    public function notify(NotificationType $type, Subscription $subscription): bool
    {
        $owner = $subscription->tenant?->notifiableOwner();

        if (! $owner) {
            Log::warning('BillingNotifier: skipped, owner has no verified WhatsApp number', [
                'type' => $type->value,
                'subscription_id' => $subscription->id,
                'tenant_id' => $subscription->tenant_id,
            ]);

            return false;
        }

        // Every placeholder is passed regardless of the event: render() only
        // substitutes the ones the template actually uses.
        return $this->notifications->send($type, $owner->whatsapp_number, [
            'name' => $owner->name,
            'plan' => $subscription->plan?->name ?? '',
            'due_date' => $subscription->current_period_end?->format('d/m/Y') ?? '',
            'amount' => 'R$ ' . number_format($subscription->price_cents / 100, 2, ',', '.'),
        ], $owner->id);
    }
}
