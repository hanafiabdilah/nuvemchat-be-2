<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger behind `ai_credit_wallets.balance_cents`.
 *
 * The balance alone answers "how much is left" and nothing else. The question
 * support actually gets is "why is it this much", and only a row per movement
 * answers that — which is also why `balance_after_cents` is stored rather than
 * recomputed: a ledger that cannot be checked against the balance it claims to
 * explain is not evidence of anything.
 *
 * The two nullable foreign keys are unique, and that uniqueness is the
 * idempotency:
 *
 *  - `invoice_id` — MercadoPago delivers the same webhook more than once, and
 *    a credit applied twice is money we gave away.
 *  - `ai_hub_run_id` — a debit is written after the run is persisted; a retry
 *    of the surrounding job must not charge for the same run again.
 *
 * Both are enforced by the database rather than by a check-then-write, because
 * webhook deliveries and queue workers are concurrent by nature.
 *
 * `cost_usd`, `usd_brl_rate` and `markup_pct` are copied onto the row instead
 * of being read back from config at display time: the rate and the markup are
 * settings, settings change, and a debit from March must still explain itself
 * in July.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            // Signed: positive credits the wallet, negative debits it. One
            // column rather than a pair, so summing the ledger is the balance.
            $table->bigInteger('amount_cents');
            $table->bigInteger('balance_after_cents');
            $table->string('currency', 3)->default('BRL');

            $table->foreignId('ai_hub_run_id')->nullable()->unique()
                ->constrained('ai_hub_runs')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->unique()
                ->constrained('invoices')->nullOnDelete();

            $table->decimal('cost_usd', 14, 8)->nullable();
            $table->decimal('usd_brl_rate', 10, 4)->nullable();
            $table->decimal('markup_pct', 6, 2)->nullable();

            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_transactions');
    }
};
