<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The unit of two amounts that have been stored without one.
 *
 * `subscriptions.price_cents` is a snapshot taken when the workspace
 * subscribed, but its currency was not: every charge since has read it live off
 * `plans.currency`. With one currency that was invisible. With two it is a
 * bug with teeth — editing a plan's currency would silently re-denominate
 * every subscription already on it, same integer, new money, on the next
 * renewal.
 *
 * `apiway_subscriptions` carries `unit_price_cents` / `total_price_cents` and
 * no currency at all; the amount is debited from the prepaid balance, which is
 * about to stop being Brazilian for every workspace.
 *
 * Both are backfilled from what they can only have meant: the plan's currency,
 * or the market the workspace is in. Every row today is Brazilian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->char('currency', 3)->default('BRL')->after('price_cents');
        });

        Schema::table('apiway_subscriptions', function (Blueprint $table) {
            $table->char('currency', 3)->default('BRL')->after('total_price_cents');
        });

        // The plan is where the currency has always been read from, so taking
        // it now is not a guess — it is the same answer the charge path would
        // have produced a minute ago, frozen before it can move.
        DB::table('subscriptions')
            ->whereIn('plan_id', DB::table('plans')->select('id'))
            ->update([
                'currency' => DB::raw('(select coalesce(plans.currency, \'BRL\') from plans where plans.id = subscriptions.plan_id)'),
            ]);

        DB::table('apiway_subscriptions')
            ->update([
                'currency' => DB::raw('(select coalesce(markets.currency, \'BRL\') from tenants join markets on markets.code = tenants.market_code where tenants.id = apiway_subscriptions.tenant_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('apiway_subscriptions', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
