<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            // Surfaced to the SPA so the mailbox can show "syncing" instead of an
            // empty inbox while the first backfill is still running.
            $table->string('sync_status')->default('idle')->after('last_synced_at');
            $table->text('sync_error')->nullable()->after('sync_status');
            // Messages still waiting on the IMAP server after the last batch.
            // Null means "not known yet" (no sync pass has completed).
            $table->unsignedInteger('sync_remaining')->nullable()->after('sync_error');
            // Lets a stuck 'syncing' (queue worker died mid-run) be detected and
            // retried instead of pinning the UI on a spinner forever.
            $table->timestamp('sync_started_at')->nullable()->after('sync_remaining');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['sync_status', 'sync_error', 'sync_remaining', 'sync_started_at']);
        });
    }
};
