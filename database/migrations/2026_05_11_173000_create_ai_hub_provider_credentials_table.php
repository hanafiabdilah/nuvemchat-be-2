<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_hub_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_hub_tenant_id')->constrained('ai_hub_tenants')->cascadeOnDelete();
            $table->string('hub_provider_credential_id')->unique();
            $table->string('provider');
            $table->string('name');
            $table->string('key_preview')->nullable();
            $table->string('default_model')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ai_hub_tenant_id', 'status']);
            $table->index(['ai_hub_tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_hub_provider_credentials');
    }
};
