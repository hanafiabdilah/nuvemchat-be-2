<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voice calls answered from the dashboard (WhatsApp Cloud API Calling).
 *
 * The thread already carries a note per call (CallLog, `messages`), and that
 * note stays the record an agent reads. This table is the live state behind
 * it: who was rung, who picked up, whether the call is still running and when
 * the browser on it last said so. A note cannot hold that — it is a message,
 * every write to it moves the conversation's sync cursor, and "ringing for
 * Ana, Bruno and Carla" is not something a reader should find in a thread.
 *
 * Channel-agnostic on purpose: the API Way call node will be able to use it
 * once its core can carry media.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            // The thread's call note (CallLog), so the two never have to be
            // matched up again by call id.
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32);
            // The channel's own call id (Meta's `wacid.…`).
            $table->string('external_id', 191);
            $table->string('direction', 16)->default('incoming');
            $table->string('status', 16);
            $table->string('end_reason', 32)->nullable();
            // Kept only while the call is being set up: the offer is what the
            // answering browser needs, the answer is what `accept` has to repeat
            // byte for byte after `pre_accept`. Both are cleared when it ends.
            $table->longText('offer_sdp')->nullable();
            $table->longText('answer_sdp')->nullable();
            $table->foreignId('answered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('rung_user_ids')->nullable();
            $table->json('declined_user_ids')->nullable();
            $table->timestamp('ringing_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['answered_by_user_id', 'status']);
            $table->index(['status', 'ringing_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
