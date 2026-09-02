<?php

namespace App\Services\Credits;

use App\Enums\Credit\CreditTransactionType;
use App\Events\CreditUpdated;
use App\Enums\Notification\NotificationType;
use App\Exceptions\Billing\InsufficientCreditException;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\AiHubRun;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Billing\BillingNotifier;
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
class CreditService
{
    /**
     * The tenant's wallet, created on first use.
     *
     * `firstOrCreate` rather than a nullable read: every caller wants a wallet
     * to talk about, and a workspace with no row has a balance of zero, which
     * is exactly what a fresh row says.
     */
    public function wallet(Tenant $tenant): CreditWallet
    {
        return CreditWallet::firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['balance_cents' => 0, 'currency' => 'BRL'],
        );
    }

    public function balanceCents(Tenant $tenant): int
    {
        return (int) (CreditWallet::where('tenant_id', $tenant->id)->value('balance_cents') ?? 0);
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
     * Whether the workspace can pay a price that is known before the fact.
     *
     * Deliberately stricter than `canSpend()`, and the difference is the whole
     * reason both exist. An AI run is allowed to overdraw because its price
     * arrives with its result; an API Way instance and a trained agent hire are
     * quoted first, so there is no excuse for taking on a debt the customer
     * never agreed to. Anything priced up front goes through here.
     */
    public function canAfford(Tenant $tenant, int $amountCents): bool
    {
        return $this->balanceCents($tenant) >= $amountCents;
    }

    /**
     * Charge something whose price was known before it was bought.
     *
     * `$reference` is what makes the charge happen once. Unlike a run or an
     * invoice there is no row elsewhere to hang uniqueness on — a renewal is an
     * event, not an entity — so the caller names it ("apiway:renew:12:2026-10-01")
     * and the ledger's unique index refuses the second attempt. Getting null
     * back means the charge is already there, which is what the caller wanted.
     *
     * The balance is checked inside the same lock as the write. Checking it in
     * the controller and charging here would leave room for two purchases
     * started at once to both pass a check neither could pass alone.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws InsufficientCreditException when the balance will not cover it
     */
    public function debit(
        Tenant $tenant,
        int $amountCents,
        CreditTransactionType $type,
        string $reference,
        string $description,
        array $meta = [],
    ): ?CreditTransaction {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('A debit must be a positive amount.');
        }

        return $this->record(
            $tenant,
            $type,
            -$amountCents,
            [
                'reference' => $reference,
                'description' => $description,
                'meta' => $meta,
            ],
            requireCents: $amountCents,
        );
    }

    /**
     * Give back money for something the platform charged and could not deliver.
     *
     * Its own row, never an edit of the debit: the customer really was charged
     * and really was refunded, and a ledger that erases the first half cannot be
     * read against the statement the customer is holding.
     *
     * The reference is derived from the debit's rather than taken from the
     * caller, so a retried failure handler cannot pay the same purchase back
     * twice — the failure path is exactly where a job is most likely to run
     * again.
     *
     * @param  array<string, mixed>  $meta
     */
    public function reverse(
        Tenant $tenant,
        int $amountCents,
        string $debitReference,
        string $description,
        array $meta = [],
    ): ?CreditTransaction {
        if ($amountCents <= 0) {
            return null;
        }

        return $this->record(
            $tenant,
            CreditTransactionType::Reversal,
            $amountCents,
            [
                'reference' => "reversal:{$debitReference}",
                'description' => $description,
                'meta' => $meta + ['reverses' => $debitReference],
            ],
        );
    }

    /**
     * Give back a debit named by its reference, for the amount it actually took.
     *
     * Reads the amount off the original row instead of trusting a caller to
     * remember it: the failure handler runs long after the charge, often in a
     * different job, and a price that has been re-quoted meanwhile would return
     * an amount the customer never paid. No debit found means nothing was
     * charged — which is a normal outcome for a purchase that failed before it
     * ever reached the wallet.
     *
     * @param  array<string, mixed>  $meta
     */
    public function reverseByReference(
        Tenant $tenant,
        string $debitReference,
        string $description,
        array $meta = [],
    ): ?CreditTransaction {
        $debit = CreditTransaction::where('tenant_id', $tenant->id)
            ->where('reference', $debitReference)
            ->first();

        if ($debit === null) {
            return null;
        }

        return $this->reverse($tenant, abs((int) $debit->amount_cents), $debitReference, $description, $meta);
    }

    /**
     * Charge one AI run to the wallet.
     *
     * Called after the run is persisted, because the cost is part of the
     * result. Returns null when the run had already been charged — a job retry
     * that got as far as persisting the run before failing.
     */
    public function chargeRun(AiHubRun $run): ?CreditTransaction
    {
        $tenant = $run->tenant;

        if ($tenant === null) {
            return null;
        }

        $price = CreditPricing::priceRun(
            $run->cost_usd === null ? null : (float) $run->cost_usd,
            $run->provider,
            $run->model,
        );

        if ($price['estimated']) {
            // Worth a line every time: a hub that stops reporting cost turns
            // this whole offering into a flat fee per run without anything
            // else in the product changing, and the only trace would be a
            // ledger full of identical amounts.
            Log::warning('CreditService: run priced from the fallback, the hub reported no cost', [
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
    public function creditTopup(Invoice $invoice): ?CreditTransaction
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
            CreditWallet::where('tenant_id', $tenant->id)->update(['low_balance_notified_at' => null]);
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
    public function reverseTopup(Invoice $invoice): ?CreditTransaction
    {
        $tenant = $invoice->tenant;

        if ($tenant === null) {
            return null;
        }

        $credited = CreditTransaction::where('invoice_id', $invoice->id)
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
    public function adjust(Tenant $tenant, int $amountCents, string $description, array $meta = []): ?CreditTransaction
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
     * index on `ai_hub_run_id` / `invoice_id` / `reference` refused the
     * duplicate. That is a success from the caller's point of view: the charge
     * exists.
     *
     * `$requireCents` is the balance the wallet must actually hold, checked
     * under the same lock as the write. Only up-front purchases pass it; a run's
     * debit leaves it null because refusing to record a cost already incurred
     * would not un-spend it.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InsufficientCreditException
     */
    protected function record(
        Tenant $tenant,
        CreditTransactionType $type,
        int $amountCents,
        array $attributes = [],
        ?int $requireCents = null,
    ): ?CreditTransaction {
        if ($amountCents === 0) {
            return null;
        }

        $this->wallet($tenant);

        try {
            $transaction = DB::transaction(function () use ($tenant, $type, $amountCents, $attributes, $requireCents) {
                /** @var CreditWallet $wallet */
                $wallet = CreditWallet::where('tenant_id', $tenant->id)->lockForUpdate()->first();

                if ($requireCents !== null && $wallet->balance_cents < $requireCents) {
                    throw new InsufficientCreditException($wallet->balance_cents, $requireCents, $wallet->currency);
                }

                $balanceAfter = $wallet->balance_cents + $amountCents;

                $transaction = CreditTransaction::create(array_merge([
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

            Log::info('CreditService: movement already recorded, skipping', [
                'tenant_id' => $tenant->id,
                'type' => $type->value,
                'ai_hub_run_id' => $attributes['ai_hub_run_id'] ?? null,
                'invoice_id' => $attributes['invoice_id'] ?? null,
                'reference' => $attributes['reference'] ?? null,
            ]);

            return null;
        }

        CreditUpdated::dispatch($tenant->id, $transaction->balance_after_cents, $type->value);

        if ($amountCents < 0) {
            $this->warnIfLow($tenant, $transaction->balance_after_cents);
        }

        return $transaction;
    }

    /**
     * Tell the workspace once, on the way down, that the balance is nearly out.
     *
     * `low_balance_notified_at` has existed since the wallet shipped and was
     * only ever cleared, never set — the warning was designed and never built.
     * While the balance paid for AI runs alone that was a small gap; now it also
     * pays the renewal that keeps a WhatsApp number alive, and running out
     * silently costs the number.
     *
     * Only on the way down: checked after a debit, so a workspace that has
     * never spent anything is not told it is running out of money it never had.
     * The stamp keeps it to one message per drop and `creditTopup()` clears it,
     * which is what makes the next drop notifiable again.
     *
     * Never allowed to throw. This runs inside the same call that just charged
     * for an AI reply on its way to a customer; a messaging failure must not
     * turn into a failed reply.
     */
    protected function warnIfLow(Tenant $tenant, int $balanceCents): void
    {
        $threshold = CreditPricing::lowBalanceCents();

        if ($threshold <= 0 || $balanceCents >= $threshold) {
            return;
        }

        try {
            $wallet = CreditWallet::where('tenant_id', $tenant->id)->first();

            if ($wallet === null || $wallet->low_balance_notified_at !== null) {
                return;
            }

            // Stamped before sending: two overlapping queue workers must not
            // both decide they are the one to warn.
            $stamped = CreditWallet::where('tenant_id', $tenant->id)
                ->whereNull('low_balance_notified_at')
                ->update(['low_balance_notified_at' => now()]);

            if ($stamped === 0) {
                return;
            }

            app(BillingNotifier::class)->notifyTenant(NotificationType::CreditLowBalance, $tenant, [
                'amount' => 'R$ '.number_format(max(0, $balanceCents) / 100, 2, ',', '.'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CreditService: could not send the low balance warning', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
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
