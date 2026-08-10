<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reactions were built for 1:1 chats, where "one reaction per side" holds
     * and (message_id, sender_type) is a sound key. In a group any number of
     * members react to the same message, so that key made each new reaction
     * overwrite the previous member's.
     *
     * The reactor now goes in contact_id — the same shape messages.contact_id
     * uses for group senders: null in a private chat, the member in a group.
     *
     * Note MySQL treats NULLs as distinct in a unique index, so this does not
     * constrain private chats; there `updateOrCreate` keyed on a null
     * contact_id keeps last-reaction-wins.
     *
     * Dropping the composite unique is safe for the message_id foreign key:
     * message_reactions_message_id_index covers it independently.
     */
    public function up(): void
    {
        Schema::table('message_reactions', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('message_id')->constrained()->nullOnDelete();
        });

        Schema::table('message_reactions', function (Blueprint $table) {
            $table->dropUnique(['message_id', 'sender_type']);
        });

        Schema::table('message_reactions', function (Blueprint $table) {
            $table->unique(['message_id', 'sender_type', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::table('message_reactions', function (Blueprint $table) {
            $table->dropUnique(['message_id', 'sender_type', 'contact_id']);
        });

        Schema::table('message_reactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });

        Schema::table('message_reactions', function (Blueprint $table) {
            $table->unique(['message_id', 'sender_type']);
        });
    }
};
