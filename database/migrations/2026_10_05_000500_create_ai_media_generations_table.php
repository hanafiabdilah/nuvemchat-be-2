<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One image, audio or video a flow asked the AI Hub to make.
     *
     * A row of its own rather than a line in `ai_hub_runs`: that table is keyed
     * to an agent and a turn of conversation, and a generation has neither. It
     * is also the job's memory — a video takes minutes, the job polls, and
     * between polls this row is the only thing that knows a generation is in
     * flight and what it has already cost.
     */
    public function up(): void
    {
        Schema::create('ai_media_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('flow_state_id')->nullable();
            $table->unsignedBigInteger('flow_node_id')->nullable();
            $table->unsignedBigInteger('ai_hub_provider_credential_id')->nullable();
            $table->string('external_id', 64)->unique();
            $table->string('hub_generation_id')->nullable();
            $table->string('type', 10);
            $table->string('status', 20)->default('pending');
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->text('prompt')->nullable();
            $table->json('request')->nullable();
            $table->string('path')->nullable();
            $table->string('mime_type', 80)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('polls')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_media_generations');
    }
};
