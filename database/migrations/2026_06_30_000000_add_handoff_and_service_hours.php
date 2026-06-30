<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI → human handoff signalling on conversations, plus per-tenant service
     * (business) hours that gate when handoff to a human is allowed.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Set when the AI stops and a human agent needs to take over. The
            // conversation stays unassigned (user_id null) in the Pending queue.
            $table->boolean('needs_human')->default(false)->after('status');
            $table->string('handoff_reason')->nullable()->after('needs_human');
            $table->timestamp('handoff_at')->nullable()->after('handoff_reason');
        });

        Schema::table('tenants', function (Blueprint $table) {
            // { enabled, timezone, days: { mon: [{open,close}], ... }, away_message }
            $table->json('service_hours')->nullable()->after('current_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['needs_human', 'handoff_reason', 'handoff_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('service_hours');
        });
    }
};
