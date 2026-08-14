<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Starring is a property of the message, not of one agent — the same
     * reasoning as `conversations.muted_at`: every message event travels on a
     * shared per-connection channel, so a per-user flag would either leak one
     * agent's stars to every subscriber or need the payload filtered per
     * subscriber. In a shared inbox the useful reading is the team's anyway:
     * "the things this workspace wants to keep".
     *
     * A timestamp rather than a boolean keeps "since when", which is what the
     * starred list is ordered by.
     *
     * The index is partial in spirit — starred rows are a tiny fraction of a
     * messages table that grows without limit, and the list endpoint's only
     * filter is `starred_at IS NOT NULL` ordered by that same column.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('starred_at')->nullable()->after('unsend_at');
            $table->index('starred_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['starred_at']);
            $table->dropColumn('starred_at');
        });
    }
};
