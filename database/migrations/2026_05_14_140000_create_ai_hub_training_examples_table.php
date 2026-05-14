<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_hub_training_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_hub_agent_id')
                ->constrained('ai_hub_agents')
                ->cascadeOnDelete();
            $table->string('hub_example_id')->unique();
            $table->string('type')->default('style_example');
            $table->text('input');
            $table->text('expected_output');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('ai_hub_agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_hub_training_examples');
    }
};
