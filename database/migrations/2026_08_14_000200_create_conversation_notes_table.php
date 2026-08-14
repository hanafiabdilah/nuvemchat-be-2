<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal notes about one conversation.
     *
     * Deliberately its own table rather than a row in `messages`: a note never
     * travelled over a channel, is never sent to the customer, and must never
     * be able to reach one by accident — keeping it out of the table every send
     * path reads from is the cheapest way to guarantee that. It also keeps
     * notes out of the message delta sync, the unread count and the
     * conversation's last-message preview, none of which a note belongs in.
     *
     * Scoped to the conversation, not the contact: the same customer can come
     * back weeks later on a new thread, and "prometi retorno até sexta" is
     * about the thread it was written in, not about the person forever.
     *
     * `user_id` is nullable so a note outlives the agent who wrote it — the
     * account can be deleted, the note is still the workspace's record.
     */
    public function up(): void
    {
        Schema::create('conversation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            // The only query there is: this conversation's notes, newest first.
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_notes');
    }
};
