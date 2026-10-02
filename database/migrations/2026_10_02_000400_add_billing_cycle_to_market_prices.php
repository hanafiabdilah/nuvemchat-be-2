<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One plan, several prices: a monthly and a yearly price on the same plan.
 *
 * Until now a plan had exactly one cycle, so selling "One" monthly and yearly
 * meant two plans — two rows of quotas and features to keep identical by hand,
 * and two names the customer reads as two different products. The cycle moves
 * beside the price, where the other "what does it cost here" answers already
 * live: a row is now one plan's price in one country **for one cycle**, and its
 * existence is the decision to sell that cycle there.
 *
 * Backfilled from the plan's own cycle, so every plan keeps selling exactly the
 * one cycle it sold before this ran. Trained agents share the table and are not
 * a subscription: their rows keep a null cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_prices', function (Blueprint $table) {
            $table->string('billing_cycle', 16)->nullable()->after('market_code');
        });

        Plan::withTrashed()->get(['id', 'billing_cycle'])->each(
            fn (Plan $plan) => DB::table('market_prices')
                ->where('priceable_type', Plan::class)
                ->where('priceable_id', $plan->id)
                ->update(['billing_cycle' => $plan->billing_cycle?->value ?? 'monthly']),
        );

        Schema::table('market_prices', function (Blueprint $table) {
            // ⚠️ The foreign key on market_code leans on this index in MySQL,
            // so the wider one has to exist before the old one goes.
            $table->unique(['priceable_type', 'priceable_id', 'market_code', 'billing_cycle'], 'market_prices_cycle_unique');
        });

        Schema::table('market_prices', function (Blueprint $table) {
            $table->dropUnique('market_prices_unique');
        });
    }

    public function down(): void
    {
        // Back to one price per country: keep each plan's own cycle, drop the rest.
        Plan::withTrashed()->get(['id', 'billing_cycle'])->each(
            fn (Plan $plan) => DB::table('market_prices')
                ->where('priceable_type', Plan::class)
                ->where('priceable_id', $plan->id)
                ->where('billing_cycle', '!=', $plan->billing_cycle?->value ?? 'monthly')
                ->delete(),
        );

        Schema::table('market_prices', function (Blueprint $table) {
            $table->unique(['priceable_type', 'priceable_id', 'market_code'], 'market_prices_unique');
        });

        Schema::table('market_prices', function (Blueprint $table) {
            $table->dropUnique('market_prices_cycle_unique');
            $table->dropColumn('billing_cycle');
        });
    }
};
