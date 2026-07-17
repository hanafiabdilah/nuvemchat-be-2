<?php

namespace App\Console\Commands\Billing;

use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Notification\NotificationType;
use App\Models\Subscription;
use App\Services\Billing\BillingNotifier;
use Illuminate\Console\Command;

class SendDueReminders extends Command
{
    protected $signature = 'billing:send-due-reminders {--days-before=1 : Days before the period ends to remind}';

    protected $description = 'Remind owners whose subscription is about to fall due';

    /**
     * Deliberately separate from billing:pix-generate, which only looks at pix
     * subscriptions (card payers would never be reminded) and can re-run once its
     * pending invoice expires. Covers both payment methods; Manual (comp) grants
     * are excluded by the status filter.
     */
    public function handle(BillingNotifier $notifier): int
    {
        $target = now()->addDays((int) $this->option('days-before'))->toDateString();

        $due = Subscription::query()
            ->with(['tenant.user', 'plan'])
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
            ->whereNotNull('current_period_end')
            ->whereDate('current_period_end', $target)
            // Not yet reminded in the current cycle: the marker predates the period
            // it belongs to once the subscription renews.
            ->where(fn ($q) => $q->whereNull('due_reminder_sent_at')
                ->orWhereColumn('due_reminder_sent_at', '<', 'current_period_start'))
            ->get();

        $queued = 0;

        foreach ($due as $subscription) {
            // Only mark it when something was actually queued: stamping a
            // suppressed reminder would claim this cycle was handled when it
            // was not, and block a retry once the toggle is turned back on.
            if ($notifier->notify(NotificationType::SubscriptionDue, $subscription)) {
                $subscription->forceFill(['due_reminder_sent_at' => now()])->save();
                $queued++;
            }
        }

        $this->info("{$due->count()} subscription(s) due on {$target}; queued {$queued} reminder(s).");

        return self::SUCCESS;
    }
}
