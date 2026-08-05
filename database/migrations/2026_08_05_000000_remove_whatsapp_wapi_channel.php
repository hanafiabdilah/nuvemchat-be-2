<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The W-API channel was removed from the codebase. Leftover rows would break
 * model hydration (the `whatsapp_w_api` enum case no longer exists), so this
 * is a last-resort purge. The proper decommission — vendor-side disconnect +
 * managed-instance deletion — is `connections:cleanup-whatsapp-wapi`, which
 * must run on the PREVIOUS release before deploying this one; rows deleted
 * here skip that vendor-side cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        $leftover = DB::table('connections')->where('channel', 'whatsapp_w_api')->count();

        if ($leftover > 0) {
            Log::warning('Purging leftover W-API connections without vendor-side disconnect', [
                'count' => $leftover,
            ]);

            // FK cascade removes their conversations and messages.
            DB::table('connections')->where('channel', 'whatsapp_w_api')->delete();
        }

        DB::table('settings')->where('key', 'wapi.managed_token')->delete();
    }

    public function down(): void
    {
        // Irreversible: the purged connections and the W-API channel code are gone.
    }
};
