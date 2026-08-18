<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tenant entitlement overrides, layered on top of whatever the plan says.
     *
     * Support constantly needs to say "give this account the funnel for a month"
     * or "raise their connection cap while they migrate". Today the only tools
     * are inventing a private plan or comping a full subscription — both of
     * which lose the customer's real billing state. This column keeps the plan
     * intact and records the exception next to the account it belongs to.
     *
     * Shape: {"features": {"crm": true}, "quotas": {"max_connections": 10},
     *         "expires_at": "2026-09-18T00:00:00Z", "note": "...",
     *         "granted_by": 3, "granted_at": "..."}
     *
     * An expired override is ignored by SubscriptionGate rather than deleted —
     * the row is the audit trail of what was granted and until when.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('entitlement_overrides')->nullable()->after('lead_settings');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('entitlement_overrides');
        });
    }
};
