<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inbound WhatsApp messages that arrived with no phone number to key them by.
     *
     * whatsmeow re-delivers a message it first failed to decrypt with the sender
     * reduced to a bare `@lid`, and a first message from somebody new — every ad
     * lead — is exactly the one most likely to need that retry. With no contact
     * holding that `@lid` yet there is nobody to attach it to, and it used to be
     * dropped: the lead saw their message delivered, the business phone showed
     * it, and the inbox and the flow never heard of it.
     *
     * They wait here instead, and are replayed the moment any later event shows
     * which number that `@lid` belongs to. The row outlives the replay for a few
     * days on purpose — the application log does not survive a deploy, and this
     * is the only record that the message ever came.
     */
    public function up(): void
    {
        Schema::create('parked_inbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('lid');
            $table->string('message_id');
            $table->longText('payload');
            $table->timestamp('replayed_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'message_id']);
            $table->index(['connection_id', 'lid', 'replayed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parked_inbound_messages');
    }
};
