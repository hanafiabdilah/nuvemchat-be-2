<?php

namespace App\Console\Commands\Billing;

use App\Models\ApiwaySubscription;
use App\Services\Billing\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Move API Way subscriptions still on a MercadoPago card off auto-debit.
 *
 * New purchases have not created a preapproval since the balance became the
 * payment method, but the ones sold before it are still charging cards every
 * cycle. They keep working — apiway:renew skips a subscription with live
 * auto-debit precisely so nobody is billed twice — so there is no deadline
 * here and no reason to do this automatically.
 *
 * ⚠️ Run deliberately, and warn the customers first. Cancelling a preapproval
 * hands the renewal to the balance, and a customer whose balance is empty loses
 * the number permanently at the next expiry: ProxyBR has no grace period. The
 * safe order is top-up first, detach second — which is why this is a command an
 * operator runs per tenant, not a migration.
 *
 * Not the same thing as cancelling the subscription. The instance keeps running;
 * only the payment instrument changes.
 */
class DetachApiwayCards extends Command
{
    protected $signature = 'apiway:detach-cards
                            {--tenant= : Limit to one tenant id}
                            {--dry-run : List what would be detached and stop}';

    protected $description = 'Cancel legacy MercadoPago auto-debit on API Way subscriptions so renewals use the balance';

    public function handle(BillingService $billing): int
    {
        $rows = ApiwaySubscription::query()
            ->renewable()
            ->whereNotNull('mp_preapproval_id')
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->with('tenant')
            ->get()
            // `autopay_off` already means "MercadoPago is not charging this" —
            // a preapproval the customer cancelled themselves, say. Touching it
            // again would be a second cancel for no change in behaviour.
            ->reject(fn (ApiwaySubscription $row) => (bool) ($row->meta['autopay_off'] ?? false));

        if ($rows->isEmpty()) {
            $this->info('No API Way subscriptions are still on card auto-debit.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'tenant', 'qty', 'cycle', 'expires', 'preapproval'],
            $rows->map(fn (ApiwaySubscription $row) => [
                $row->id,
                $row->tenant_id,
                $row->quantity,
                $row->cycle,
                $row->expires_at?->toDateString() ?? '—',
                $row->mp_preapproval_id,
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment($rows->count().' subscription(s) would be detached. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Detach these from card auto-debit? Their next renewal will be charged to the tenant balance.', false)) {
            return self::SUCCESS;
        }

        $detached = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $billing->mercadoPago()->cancelPreapproval($row->mp_preapproval_id);
            } catch (\Throwable $e) {
                // Reported, not swallowed: a preapproval we failed to cancel is
                // still charging the card, and marking it detached here would
                // set up exactly the double charge this whole path avoids.
                $this->error("#{$row->id}: {$e->getMessage()}");
                Log::error('Failed to cancel an apiway preapproval during cutover', [
                    'apiway_subscription_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;

                continue;
            }

            // `mp_preapproval_id` is kept: it is the trail back to the charges
            // MercadoPago already made against this subscription.
            $row->forceFill(['meta' => array_merge($row->meta ?? [], ['autopay_off' => true])])->save();
            $detached++;
        }

        $this->info("Detached: {$detached}, failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
