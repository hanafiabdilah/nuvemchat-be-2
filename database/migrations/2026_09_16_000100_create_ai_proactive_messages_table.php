<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One message the AI Hub pushed into a conversation on its own initiative —
 * see AiProactiveMessageService.
 *
 * The unique (tenant_id, idempotency_key) is what makes the caller's retry
 * safe. The hub retries on a timeout, and a timeout can arrive *after* we
 * already handed the text to WhatsApp: without this row, the retry is a second
 * message in the customer's chat. The row is written before the send precisely
 * so it exists in that gap, and `result` is the first call's response body,
 * replayed verbatim to every retry (same arrangement as `lead_intakes`).
 *
 * It doubles as the audit trail. This is the one surface where text authored
 * outside the platform reaches a customer in a bubble that reads as the
 * business, so "who sent what, under which key, into which thread" has to be
 * answerable from a row rather than from a log line that a deploy will delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_proactive_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('idempotency_key', 191);
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_hub_agent_id')->nullable()->constrained('ai_hub_agents')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            // "What has this thread been pushed lately" — read by the
            // per-conversation ceiling and by anyone diagnosing a complaint.
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proactive_messages');
    }
};
