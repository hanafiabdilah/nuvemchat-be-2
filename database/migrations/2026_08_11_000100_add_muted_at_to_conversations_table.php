<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Muting is a property of the conversation, not of one agent: every event
     * this inbox raises travels on the shared tenant channel, so a per-user
     * flag would have to be filtered per subscriber. A timestamp rather than a
     * boolean keeps "since when" (and leaves room for a timed mute later).
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('muted_at')->nullable()->after('handoff_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('muted_at');
        });
    }
};
