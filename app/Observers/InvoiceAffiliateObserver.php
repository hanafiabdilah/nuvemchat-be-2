<?php

namespace App\Observers;

use App\Enums\Billing\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Affiliates\AffiliateCommissions;
use Illuminate\Support\Facades\DB;

/**
 * Feeds the affiliate program from the one place every payment ends up: an
 * invoice turning paid (or refunded).
 *
 * In an observer rather than in the dozen paths that mark an invoice paid
 * (card, Pix, hosted checkout, renewals, webhooks, read-backs), because a
 * hook per path would be complete the day it was written and quietly miss the
 * next payment path. After commit, so a billing transaction that rolls back
 * never leaves a commission behind, and nothing here can roll billing back.
 */
class InvoiceAffiliateObserver
{
    public function saved(Invoice $invoice): void
    {
        $becamePaid = $invoice->status === InvoiceStatus::Paid
            && ($invoice->wasRecentlyCreated || $invoice->wasChanged('status'));
        $becameRefunded = $invoice->status === InvoiceStatus::Refunded && $invoice->wasChanged('status');

        if (! $becamePaid && ! $becameRefunded) {
            return;
        }

        DB::afterCommit(function () use ($invoice, $becamePaid) {
            $commissions = app(AffiliateCommissions::class);

            $becamePaid
                ? $commissions->onInvoicePaid($invoice->fresh() ?? $invoice)
                : $commissions->onInvoiceRefunded($invoice);
        });
    }
}
