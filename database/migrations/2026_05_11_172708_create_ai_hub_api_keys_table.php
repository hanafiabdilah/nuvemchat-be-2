<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_hub_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_hub_tenant_id')->constrained('ai_hub_tenants')->cascadeOnDelete();
            $table->string('hub_api_key_id')->unique();
            $table->string('name')->nullable();
            $table->string('key_preview')->nullable();
            $table->text('api_key');
            $table->string('status')->default('ACTIVE');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['ai_hub_tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_hub_api_keys');
    }
};
