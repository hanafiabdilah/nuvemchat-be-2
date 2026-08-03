<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            // How far back to import mail, in days. Null = the whole mailbox.
            $table->unsignedSmallInteger('sync_window_days')->nullable()->after('last_seen_uid');
            // Lowest UID the newest-first backfill has imported so far.
            // Null = the backfill has not imported anything yet.
            $table->unsignedBigInteger('backfill_uid')->nullable()->after('sync_window_days');
            $table->boolean('backfill_done')->default(false)->after('backfill_uid');
        });

        // Mailboxes already walked by the old oldest-first sync hold their
        // history locally (everything up to last_seen_uid); without this they
        // would re-scan the whole mailbox from the top on the next pass.
        DB::table('connections')
            ->where('channel', 'email')
            ->where('last_seen_uid', '>', 0)
            ->update(['backfill_done' => true]);
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['sync_window_days', 'backfill_uid', 'backfill_done']);
        });
    }
};
