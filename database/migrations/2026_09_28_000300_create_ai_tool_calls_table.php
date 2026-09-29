<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every tool the AI Hub asked us to run — see AiToolCallService.
 *
 * Two jobs, like `ai_proactive_messages` beside it. The unique
 * (tenant_id, idempotency_key) makes the hub's retry safe: a timeout on our
 * side says nothing about whether the Pix was already issued, so the row is
 * written before the tool runs and a retry is answered from it. And it is the
 * audit trail: production keeps no application logs across deploys, so "why
 * did the bot charge that amount" is answerable from here or not at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('idempotency_key', 191);
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_state_id')->nullable()->constrained('flow_states')->nullOnDelete();
            $table->foreignId('flow_node_id')->nullable()->constrained('flow_nodes')->nullOnDelete();
            $table->foreignId('ai_hub_agent_id')->nullable()->constrained('ai_hub_agents')->nullOnDelete();
            $table->string('hub_run_id', 191)->nullable();
            $table->string('tool', 64);
            $table->json('arguments')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('result')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_calls');
    }
};
