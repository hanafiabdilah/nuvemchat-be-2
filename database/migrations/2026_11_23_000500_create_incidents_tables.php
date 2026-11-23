<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidents: a workspace telling everyone who writes in that something is
 * broken, once per conversation, until it is fixed (App\Services\Incident).
 *
 * Four tables, and the third is the one the design rests on.
 *
 * `incident_deliveries` holds one row per (incident, conversation, kind) and
 * the unique index on that triple is what makes the notice go out once. It
 * records a *decision*, not just a send: a conversation whose first message
 * did not match the keywords gets a `not_matched` row, and that row is what
 * stops the next message from being judged again. A conversation an agent is
 * holding gets no row at all, so if it goes back to the queue the next message
 * can still be judged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            // scheduled | active | resolved | expired | canceled
            $table->string('status', 20)->index();
            // all | keywords
            $table->string('match_mode', 20)->default('all');
            $table->json('keywords')->nullable();
            $table->json('exclude_keywords')->nullable();
            // fixed | ai. In `ai` the notice is written per customer by an AI
            // agent from `ai_brief`; `message` is still required — it is what
            // goes out when the AI cannot answer (no balance, hub down).
            $table->string('reply_mode', 20)->default('fixed');
            $table->foreignId('ai_hub_agent_id')->nullable()->constrained('ai_hub_agents')->nullOnDelete();
            // What the AI is told about the incident. Also handed to the
            // workspace's AI agents in every turn while the incident is live.
            $table->text('ai_brief')->nullable();
            $table->text('message');
            $table->string('media_url', 2048)->nullable();
            // image | video | document | audio
            $table->string('media_type', 20)->nullable();
            $table->boolean('reply_in_agent_threads')->default(false);
            $table->boolean('continue_flow')->default(false);
            $table->unsignedSmallInteger('notify_recent_minutes')->default(0);
            $table->text('resolution_message')->nullable();
            // all | open
            $table->string('resolution_audience', 20)->default('all');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            // manual | api
            $table->string('source', 20)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('incident_connections', function (Blueprint $table) {
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();

            $table->primary(['incident_id', 'connection_id']);
            $table->index('connection_id');
        });

        Schema::create('incident_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            // notice | resolution
            $table->string('kind', 20);
            // pending | sending | sent | failed | skipped | not_matched
            $table->string('status', 20);
            // reactive | retro | manual | resolution
            $table->string('source', 20);
            $table->string('matched_keyword', 60)->nullable();
            $table->string('excluded_keyword', 60)->nullable();
            // The customer message that decided (notice) — kept so the incident
            // page can show what was said without guessing which message it was.
            $table->foreignId('trigger_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            // Why it was skipped or failed, as a code the dashboard translates
            // (window_closed, opted_out, incident_over, …). `error` carries the
            // channel's refusal in our own words when there is one.
            $table->string('reason', 40)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'conversation_id', 'kind']);
            $table->index(['incident_id', 'kind', 'status']);
            $table->index(['incident_id', 'connection_id', 'kind', 'status']);
        });

        Schema::create('incident_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('title', 120);
            $table->string('match_mode', 20)->default('all');
            $table->json('keywords')->nullable();
            $table->json('exclude_keywords')->nullable();
            $table->string('reply_mode', 20)->default('fixed');
            $table->foreignId('ai_hub_agent_id')->nullable()->constrained('ai_hub_agents')->nullOnDelete();
            $table->text('ai_brief')->nullable();
            $table->text('message');
            $table->string('media_url', 2048)->nullable();
            $table->string('media_type', 20)->nullable();
            $table->text('resolution_message')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(240);
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_templates');
        Schema::dropIfExists('incident_deliveries');
        Schema::dropIfExists('incident_connections');
        Schema::dropIfExists('incidents');
    }
};
