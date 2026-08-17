<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How this workspace wants its funnel to behave.
     *
     * A JSON column on the tenant, the same shape service_hours already uses:
     * these are a handful of related knobs read together on every pass, and
     * giving each one its own column would mean a migration every time the
     * funnel learns a new preference.
     *
     * Null means "never configured" — LeadSettings supplies the defaults, so a
     * tenant that never opens the settings dialog still gets sane behaviour and
     * nothing has to be backfilled.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('lead_settings')->nullable()->after('current_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('lead_settings');
        });
    }
};
