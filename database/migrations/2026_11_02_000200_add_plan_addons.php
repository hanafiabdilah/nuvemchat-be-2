<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra agents and WhatsApp connections bought on top of a plan.
 *
 * - `market_prices.addon_cents` — what one unit of each add-on costs **for this
 *   plan, in this country, for this cycle**, keyed by add-on (`agents`,
 *   `whatsapp_connections`). Typed by hand like the plan's own price; absent
 *   means the plan does not sell that extra there.
 * - `subscriptions.addons` — units held now, by add-on key.
 * - `subscriptions.addon_unit_cents` — the per-cycle price of one unit, frozen
 *   when it was bought, so a later price change never reaches somebody already
 *   paying.
 * - `subscriptions.scheduled_addons` / `scheduled_addons_at` — the units held
 *   from the next paid-for period on: a reduction (paid periods are never
 *   shortened) or the units a scheduled downgrade carries.
 * - `subscriptions.scheduled_addon_unit_cents` — their price on the plan a
 *   scheduled downgrade moves to.
 *
 * `price_cents` stays the plan's own price; every renewal adds the add-ons on
 * top (PlanChange::termsFor). Nullable throughout: a subscription without
 * add-ons reads exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_prices', function (Blueprint $table) {
            $table->json('addon_cents')->nullable()->after('amount_cents');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('addons')->nullable()->after('features_snapshot');
            $table->json('addon_unit_cents')->nullable()->after('addons');
            $table->json('scheduled_addons')->nullable()->after('scheduled_change_at');
            $table->timestamp('scheduled_addons_at')->nullable()->after('scheduled_addons');
            $table->json('scheduled_addon_unit_cents')->nullable()->after('scheduled_addons_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['addons', 'addon_unit_cents', 'scheduled_addons', 'scheduled_addons_at', 'scheduled_addon_unit_cents']);
        });

        Schema::table('market_prices', function (Blueprint $table) {
            $table->dropColumn('addon_cents');
        });
    }
};
