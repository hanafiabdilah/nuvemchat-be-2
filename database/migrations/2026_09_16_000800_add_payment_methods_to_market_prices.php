<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which payment methods a plan offers — per country, where the question lives.
 *
 * `plans.card_enabled` / `pix_enabled` were global, and Pix is a Brazilian rail:
 * one checkbox decided whether a method existed in every country at once, and
 * the moment a plan was priced for Indonesia it offered Pix there too. The
 * decision belongs beside the price, because both answer the same question —
 * what this plan is, in this country.
 *
 * Backfilled from the plan, so nothing changes for anyone on the day this ships:
 * a plan that sold Pix keeps selling Pix in every country it was already priced
 * for. Trained agents share this table and ignore both columns — they are not
 * bought at a checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_prices', function (Blueprint $table) {
            $table->boolean('card_enabled')->default(true)->after('currency');
            $table->boolean('pix_enabled')->default(true)->after('card_enabled');
        });

        // Row by row rather than one correlated UPDATE: this runs once, on a
        // handful of plans, and the readable version is the one somebody can
        // check against the table afterwards.
        Plan::withTrashed()->get(['id', 'card_enabled', 'pix_enabled'])->each(
            fn (Plan $plan) => DB::table('market_prices')
                ->where('priceable_type', Plan::class)
                ->where('priceable_id', $plan->id)
                ->update([
                    'card_enabled' => (bool) $plan->card_enabled,
                    'pix_enabled' => (bool) $plan->pix_enabled,
                ]),
        );
    }

    public function down(): void
    {
        Schema::table('market_prices', function (Blueprint $table) {
            $table->dropColumn(['card_enabled', 'pix_enabled']);
        });
    }
};
