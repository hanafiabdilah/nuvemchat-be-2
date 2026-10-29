<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which key the flow assistant runs on, per workspace.
 *
 * The assistant used to run on one OpenAI key the platform pasted into the
 * Back Office, and the platform paid for every turn of every workspace. Now
 * each workspace chooses, the way it does for an AI agent: its own provider
 * key, or one rented from the platform and charged to the prepaid balance.
 *
 * ── Why a table of its own, not an `ai_hub_agents` row ──
 *
 * The assistant still runs on a hub agent — the hub only runs agents — but that
 * agent carries a system prompt the platform writes and rewrites on every
 * format change. Every `ai_hub_agents` row is listed on the workspace's AI
 * Agents page, offered in the flow node's agent picker and editable there, so
 * parking it there would hand the customer a prompt to edit and an agent to
 * delete, and the assistant would break the day they did. This row holds the
 * same hub ids with none of that surface.
 *
 * `ai_hub_provider_credential_id` nulls on delete: removing the key the
 * assistant used is a decision about the key, and the assistant asks for a new
 * one the next time it is opened rather than failing at the hub.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_assistant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('ai_hub_provider_credential_id')->nullable()
                ->constrained('ai_hub_provider_credentials')->nullOnDelete();
            $table->string('model', 100)->nullable();
            $table->string('hub_agent_id')->nullable();
            $table->string('agent_external_id')->nullable();
            // Fingerprint of what was last pushed to this workspace's agent
            // (prompt, credential, model) — see FlowAssistantService::syncAgent().
            $table->string('agent_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_assistant_settings');
    }
};
