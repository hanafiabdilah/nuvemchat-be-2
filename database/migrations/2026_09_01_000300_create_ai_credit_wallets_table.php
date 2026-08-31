<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid balance for tenants running on rented platform keys.
 *
 * Prepaid rather than metered-and-invoiced for the same reason API Way
 * provisions only after the money lands: the cost here is incurred against
 * somebody else's API key, in real time, by a bot answering messages. A
 * post-paid meter would let a workspace spend the platform's money for a full
 * cycle before anyone could act on it.
 *
 * `balance_cents` is signed on purpose. A run's cost is only known once it has
 * already happened, so the last run before exhaustion can push the balance
 * below zero. Refusing to record a debit that was genuinely incurred, in order
 * to keep a column non-negative, would make the ledger a worse record than the
 * provider's own invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('balance_cents')->default(0);
            $table->string('currency', 3)->default('BRL');
            // Warned once per drop below the threshold, cleared on top-up —
            // without it a wallet sitting just under the line would notify on
            // every single run.
            $table->timestamp('low_balance_notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_wallets');
    }
};
