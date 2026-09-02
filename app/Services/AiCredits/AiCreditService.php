<?php

namespace App\Services\AiCredits;

use App\Enums\AiCredit\CreditTransactionType;
use App\Events\AiCreditUpdated;
use App\Models\AiCreditTransaction;
use App\Models\AiCreditWallet;
use App\Models\AiHubRun;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The wallet: reading a balance, moving it, and never moving it twice.
 *
 * Every movement goes through `record()`, which holds a row lock on the wallet
 * for the length of the write. That matters more here than in most places: AI
 * runs are dispatched from a queue and can overlap on the same workspace, and a
 * balance computed by two workers reading the same number is a balance that
 * loses one of the two debits.
 *
 * Double-charging is prevented by the database, not by a check: the ledger's
 * `ai_hub_run_id` and `invoice_id` are unique, so a retried job or a repeated
 * MercadoPago webhook loses the race instead of writing a second row. The
 * duplicate is swallowed on purpose — it means the movement already happened,
 * which is the outcome the caller wanted.
 */
class AiCreditService
{
    /**
     * The tenant's wallet, created on first use.
     *
     * `firstOrCreate` rather than a nullable read: every caller wants a wallet
     * to talk about, and a workspace with no row has a balance of zero, which
     * is exactly what a fresh row says.
     */
    public function wallet(Tenant $tenant): AiCreditWallet
    {
        return AiCreditWallet::firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['balance_cents' => 0, 'currency' => 'BRL'],
        );
    }

    public function balanceCents(Tenant $tenant): int
    {
        return (int) (AiCreditWallet::where('tenant_id', $tenant->id)->value('balance_cents') ?? 0);
    }

    /**
     * Whether the workspace may start one more run on a rented key.
     *
     * Only the sign of the balance is checked, because the price of the run
     * about to start is not knowable until it has finished — the hub reports
     * cost with the result. One run can therefore overdraw the wallet, by at
     * most the cost of a single run, and the next one is refused. Reserving an
     * estimate up front would be a guess that has to be reconciled afterwards,
     * for a smaller error than the one it introduces.
     */
    public function canSpend(Tenant $tenant): bool
    {
        return $this->balanceCents($tenant) > 0;
    }

    /**
     * Charge one AI run to the wallet.
     *
     * Called after the run is persisted, because the cost is part of the
     * result. Returns null when the run had already been charged — a job retry
     * that got as far as persisting the run before failing.
     */
    public function chargeRun(AiHubRun $run): ?AiCreditTransaction
    {
        $tenant = $run->tenant;

        if ($tenant === null) {
            return null;
        }

        $price = AiCreditPricing::priceRun(
            $run->cost_usd === null ? null : (float) $run->cost_usd,
            $run->provider,
            $run->model,
        );

        if ($price['estimated']) {
            // Worth a line every time: a hub that stops reporting cost turns
            // this whole offering into a flat fee per run without anything
            // else in the product changing, and the only trace would be a
            // ledger full of identical amounts.
            Log::warning('AiCreditService: run priced from the fallback, the hub reported no cost', [
                'tenant_id' => $tenant->id,
                'ai_hub_run_id' => $run->id,
                'provider' => $run->provider,
                'model' => $run->model,
                'cents' => $price['cents'],
            ]);
        }

        return $this->record(
            $tenant,
            CreditTransactionType::Usage,
            -$price['cents'],
            [
                'ai_hub_run_id' => $run->id,
                'cost_usd' => $price['cost_usd'],
                'usd_brl_rate' => $price['rate'],
                'markup_pct' => $price['markup_pct'],
                'description' => trim(($run->provider ?? 'AI') . ' ' . ($run->model ?? '')) ?: 'AI run',
                'meta' => [
                    'estimated' => $price['estimated'],
                    'total_tokens' => $run->total_tokens,
                    'conversation_id' => $run->conversation_id,
                ],
            ],
        );
    }

    /**
     * Credit a paid top-up invoice.
     *
     * Idempotent through the ledger's unique `invoice_id`: MercadoPago delivers
     * the same notification more than once, and a credit applied twice is money
     * given away.
     */
    public function creditTopup(Invoice $invoice): ?AiCreditTransaction
    {
        $tenant = $invoice->tenant;

        if ($tenant === null) {
            return null;
        }

        $transaction = $this->record(
            $tenant,
            CreditTransactionType::Topup,
            (int) $invoice->amount_cents,
            [
                'invoice_id' => $invoice->id,
                'description' => "Recarga — fatura #{$invoice->id}",
            ],
        );

        if ($transaction !== null) {
            // A workspace that has just paid is not low on credit any more, and
            // leaving the stamp set would silence the next warning.
            AiCreditWallet::where('tenant_id', $tenant->id)->update(['low_balance_notified_at' => null]);
        }

        return $transaction;
    }

    /**
     * Reverse a top-up MercadoPago later refunded or charged back.
     *
     * Written as its own negative row rather than by deleting the credit: the
     * money did arrive and then leave, and a ledger that pretends it never
     * arrived cannot be reconciled against the provider's statement.
     */
    public function reverseTopup(Invoice $invoice): ?AiCreditTransaction
    {
        $tenant = $invoice->tenant;

        if ($tenant === null) {
            return null;
        }

        $credited = AiCreditTransaction::where('invoice_id', $invoice->id)
            ->where('type', CreditTransactionType::Topup->value)
            ->exists();

        if (! $credited) {
            return null;
        }

        return $this->record(
            $tenant,
            CreditTransactionType::Refund,
            -(int) $invoice->amount_cents,
            [
                'description' => "Estorno da recarga — fatura #{$invoice->id}",
                'meta' => ['invoice_id' => $invoice->id],
            ],
        );
    }

    /**
     * A Back Office correction, either direction. The actor is recorded in the
     * meta because a manual movement without a name behind it is the one row
     * nobody can explain later.
     */
    public function adjust(Tenant $tenant, int $amountCents, string $description, array $meta = []): ?AiCreditTransaction
    {
        return $this->record(
            $tenant,
            CreditTransactionType::Adjustment,
            $amountCents,
            ['description' => $description, 'meta' => $meta],
        );
    }

    /**
     * Write one ledger row and move the balance, under a row lock.
     *
     * Returns null when the movement had already been recorded — the unique
     * index on `ai_hub_run_id` / `invoice_id` refused the duplicate. That is a
     * success from the caller's point of view: the charge exists.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function record(
        Tenant $tenant,
        CreditTransactionType $type,
        int $amountCents,
        array $attributes = [],
    ): ?AiCreditTransaction {
        if ($amountCents === 0) {
            return null;
        }

        $this->wallet($tenant);

        try {
            $transaction = DB::transaction(function () use ($tenant, $type, $amountCents, $attributes) {
                /** @var AiCreditWallet $wallet */
                $wallet = AiCreditWallet::where('tenant_id', $tenant->id)->lockForUpdate()->first();

                $balanceAfter = $wallet->balance_cents + $amountCents;

                $transaction = AiCreditTransaction::create(array_merge([
                    'tenant_id' => $tenant->id,
                    'type' => $type,
                    'amount_cents' => $amountCents,
                    'balance_after_cents' => $balanceAfter,
                    'currency' => $wallet->currency,
                ], $attributes));

                $wallet->update(['balance_cents' => $balanceAfter]);

                return $transaction;
            });
        } catch (QueryException $e) {
            if (! $this->isDuplicate($e)) {
                throw $e;
            }

            Log::info('AiCreditService: movement already recorded, skipping', [
                'tenant_id' => $tenant->id,
                'type' => $type->value,
                'ai_hub_run_id' => $attributes['ai_hub_run_id'] ?? null,
                'invoice_id' => $attributes['invoice_id'] ?? null,
            ]);

            return null;
        }

        AiCreditUpdated::dispatch($tenant->id, $transaction->balance_after_cents, $type->value);

        return $transaction;
    }

    /**
     * Whether a failed insert was the unique index doing its job.
     *
     * Matched on the SQLSTATE rather than the driver message so it behaves the
     * same on MySQL and on the SQLite the tests run against.
     */
    protected function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
