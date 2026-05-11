<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_hub_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_hub_tenant_id')->constrained('ai_hub_tenants')->cascadeOnDelete();
            $table->foreignId('ai_hub_provider_credential_id')
                ->nullable()
                ->constrained('ai_hub_provider_credentials')
                ->nullOnDelete();
            $table->string('hub_agent_id')->unique();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('model')->nullable();
            $table->text('system_prompt')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->json('handoff_rules')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['ai_hub_tenant_id', 'external_id']);
            $table->index(['ai_hub_tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_hub_agents');
    }
};
