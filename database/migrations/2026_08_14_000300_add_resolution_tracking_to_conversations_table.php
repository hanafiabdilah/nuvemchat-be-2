<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a conversation was closed, and by whom.
     *
     * `status` alone says a conversation is resolved but never when it became
     * so, and `updated_at` is not a stand-in: muting or tagging a closed thread
     * moves it. Without a real timestamp there is no honest way to report time
     * to resolution — the one service metric the statistics page could not
     * compute at all.
     *
     * Write-once by construction: an inbound message never re-opens a resolved
     * conversation, it starts a new one (see the chat handlers), so nothing
     * ever has to clear these columns.
     *
     * Historical rows stay null on purpose. Backfilling from `updated_at` would
     * invent durations that look precise and are not; the page reports "—" for
     * the period before this migration instead.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('status');
            $table->foreignId('resolved_by_user_id')->nullable()->after('resolved_at')
                ->constrained('users')->nullOnDelete();

            // Statistics buckets closures by date over a tenant's whole history.
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['resolved_at']);
            $table->dropConstrainedForeignId('resolved_by_user_id');
            $table->dropColumn('resolved_at');
        });
    }
};
