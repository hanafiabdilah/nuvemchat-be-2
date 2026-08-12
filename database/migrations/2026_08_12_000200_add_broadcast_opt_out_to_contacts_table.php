<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this contact asked to stop receiving campaigns.
     *
     * Meta requires a business sending marketing templates to honour opt-outs,
     * and the customer's only way of expressing one is replying "PARAR" to the
     * thread — so it is recorded at ingest and read by the broadcast sender.
     *
     * Scoped to campaigns, not to messaging: an agent may still reply to this
     * contact by hand, and automations still run. A timestamp rather than a
     * boolean so the date the request came in survives (that is the part that
     * matters if the tenant is ever asked to show it).
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('broadcast_opted_out_at')->nullable()->after('group_removed_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('broadcast_opted_out_at');
        });
    }
};
