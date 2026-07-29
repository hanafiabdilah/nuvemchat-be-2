<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Respond with AI" v2: instead of one provider config per tenant, a
     * tenant manages multiple AI suggest agents and links each connection to
     * one of them. Kept plain for now (name + provider + key + model) —
     * style/persona fields will be added later, mirroring the flow AI agent.
     */
    public function up(): void
    {
        Schema::create('ai_suggest_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider');
            // Encrypted via the model cast.
            $table->text('api_key');
            $table->string('model')->nullable();
            $table->timestamps();
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->foreignId('ai_suggest_agent_id')
                ->nullable()
                ->after('closing_message')
                ->constrained('ai_suggest_agents')
                ->nullOnDelete();
        });

        // Superseded by the per-agent link above.
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('ai_suggest_enabled');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('ai_suggest_config');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('ai_suggest_config')->nullable();
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->boolean('ai_suggest_enabled')->default(false);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_suggest_agent_id');
        });

        Schema::drop('ai_suggest_agents');
    }
};
