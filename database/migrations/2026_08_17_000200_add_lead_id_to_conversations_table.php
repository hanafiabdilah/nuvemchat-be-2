<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which sale this thread belongs to.
     *
     * Not the source of truth — the real link is contact_id, and the lead is
     * always findable from there. This is so the chat panel can show the card
     * without a second query, and so a resolved thread still remembers which
     * attempt it was part of after the contact has moved on to a later lead.
     *
     * nullOnDelete: deleting a lead must never take conversations with it.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('contact_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropColumn('lead_id');
        });
    }
};
