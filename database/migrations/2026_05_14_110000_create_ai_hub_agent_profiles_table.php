<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_hub_agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_hub_agent_id')
                ->unique()
                ->constrained('ai_hub_agents')
                ->cascadeOnDelete();
            $table->string('language')->nullable();
            $table->string('tone')->nullable();
            $table->string('response_style')->nullable();
            $table->json('instructions')->nullable();
            $table->json('limits')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_hub_agent_profiles');
    }
};
