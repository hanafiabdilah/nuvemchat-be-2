<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Channels re-deliver a message under the same provider id (whatsmeow does
     * it minutes later for anything it first failed to decrypt), so ingest has
     * to ask "have I stored this one already?" for every inbound message —
     * plus once per id on every delivery/read receipt. That lookup was a full
     * table scan.
     *
     * Not unique: the same provider id legitimately recurs across connections,
     * and Telegram's message_id is only unique per chat.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['external_id']);
        });
    }
};
