<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per trained agent a tenant took, whether it came out of the plan's
 * included allowance or was paid for.
 *
 * It outlives everything it points at, deliberately. The blueprint can be
 * edited or retired and the forked agent can be deleted by the tenant, but the
 * purchase still happened — `blueprint_snapshot` is what was actually sold, so
 * support can answer "what did I pay for" months later without archaeology.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trained_agent_hires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trained_agent_blueprint_id')->nullable()
                ->constrained('trained_agent_blueprints')->nullOnDelete();

            // The forked agent: null until the job finishes, and null again if
            // the tenant later deletes it from the AI Agents page.
            $table->foreignId('ai_hub_agent_id')->nullable()
                ->constrained('ai_hub_agents')->nullOnDelete();
            // Which of the tenant's own provider credentials the fork runs on.
            // The tenant pays the model bill, exactly like a hand-made agent.
            $table->foreignId('ai_hub_provider_credential_id')->nullable()
                ->constrained('ai_hub_provider_credentials')->nullOnDelete();

            // Idempotency handle used in the MercadoPago external_reference.
            $table->string('external_ref', 160)->unique();

            $table->string('source', 20)->index();   // included | purchased
            $table->string('status', 30)->index();   // pending_payment | provisioning | active | failed | cancelled

            $table->string('agent_name', 150)->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('BRL');

            $table->json('blueprint_snapshot')->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('hired_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trained_agent_hires');
    }
};
