<?php

namespace App\Console\Commands\Sales;

use App\Enums\Message\SenderType;
use App\Models\FlowPayment;
use App\Models\FlowReceipt;
use App\Models\Message;
use App\Services\Sales\AdReferrals;
use App\Services\Sales\SalesLedger;
use Illuminate\Console\Command;

/**
 * Fills the sales page with what happened before it existed.
 *
 * Ad referrals are read off the stored first messages of ad-originated
 * conversations; sales off receipts an AI already approved and gateway charges
 * already paid. Safe to run again: both writers are idempotent.
 */
class BackfillSales extends Command
{
    protected $signature = 'sales:backfill
        {--days=120 : How far back to look for ad-originated messages}
        {--dry-run : Count what would be written, write nothing}';

    protected $description = 'Record ad referrals and confirmed sales from data stored before the sales page existed';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $referrals = 0;

        // The two markers of an ad click, one per WhatsApp variant. A LIKE over
        // the payload is slow and that is acceptable here: it runs once.
        Message::where('sender_type', SenderType::Incoming)
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->where(fn ($query) => $query
                ->where('meta', 'like', '%externalAdReply%')
                ->orWhere('meta', 'like', '%"referral"%'))
            ->orderBy('id')
            ->chunkById(200, function ($messages) use (&$referrals, $dryRun) {
                foreach ($messages as $message) {
                    if (AdReferrals::read((array) $message->meta) === null) {
                        continue;
                    }

                    if ($dryRun) {
                        $referrals++;

                        continue;
                    }

                    if (AdReferrals::capture($message)?->wasRecentlyCreated) {
                        $referrals++;
                    }
                }
            });

        $sales = 0;

        FlowReceipt::where('status', FlowReceipt::STATUS_APPROVED)->chunkById(200, function ($receipts) use (&$sales, $dryRun) {
            foreach ($receipts as $receipt) {
                $sales++;
                $dryRun || SalesLedger::fromReceipt($receipt);
            }
        });

        FlowPayment::whereNotNull('paid_at')->chunkById(200, function ($payments) use (&$sales, $dryRun) {
            foreach ($payments as $payment) {
                $sales++;
                $dryRun || SalesLedger::fromPayment($payment);
            }
        });

        $this->info(($dryRun ? 'Would record' : 'Recorded')." {$referrals} ad referral(s); {$sales} confirmed sale(s) checked.");

        return self::SUCCESS;
    }
}
