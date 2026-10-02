<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan changes stop being "subscribe again from scratch".
 *
 * An upgrade is a new subscription that waits, unpaid, beside the plan it
 * replaces (`replaces_subscription_id`) and only takes over once its first
 * charge settles — the unused part of the old plan is discounted from that
 * charge (`invoices.proration_credit_cents`) or, where the gateway cannot
 * discount a first charge, paid into the balance (`proration_balance_cents`).
 *
 * A downgrade is the same subscription row changing terms at the end of what
 * is already paid for (`scheduled_*`): nothing is charged now, and nothing is
 * taken away before the customer stops having paid for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('replaces_subscription_id')->nullable()->after('plan_id')
                ->constrained('subscriptions')->nullOnDelete();
            $table->unsignedBigInteger('proration_balance_cents')->nullable()->after('price_cents');

            $table->foreignId('scheduled_plan_id')->nullable()->after('cancel_at_period_end')
                ->constrained('plans')->nullOnDelete();
            $table->unsignedBigInteger('scheduled_price_cents')->nullable()->after('scheduled_plan_id');
            $table->string('scheduled_billing_cycle')->nullable()->after('scheduled_price_cents');
            $table->timestamp('scheduled_change_at')->nullable()->after('scheduled_billing_cycle');

            $table->index('scheduled_change_at');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('proration_credit_cents')->nullable()->after('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('proration_credit_cents');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['scheduled_change_at']);
            $table->dropConstrainedForeignId('scheduled_plan_id');
            $table->dropConstrainedForeignId('replaces_subscription_id');
            $table->dropColumn(['proration_balance_cents', 'scheduled_price_cents', 'scheduled_billing_cycle', 'scheduled_change_at']);
        });
    }
};
