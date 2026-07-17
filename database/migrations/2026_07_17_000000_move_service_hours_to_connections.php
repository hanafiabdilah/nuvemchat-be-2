<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Service hours move from the tenant to the connection: a tenant may run
     * several unrelated businesses, each with its own schedule, so one global
     * blob per tenant is the wrong grain.
     *
     * Existing tenant schedules are deliberately NOT copied over — every
     * connection starts unconfigured, which BusinessHours treats as always
     * open. Schedules must be set again per connection.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            // { enabled, timezone, days: { mon: [{open,close}], ... }, away_message }
            $table->json('service_hours')->nullable()->after('closing_message');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('service_hours');
        });
    }

    /**
     * Restores the column but not its contents — the tenant schedules are gone
     * for good once up() has run.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('service_hours')->nullable()->after('current_subscription_id');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('service_hours');
        });
    }
};
