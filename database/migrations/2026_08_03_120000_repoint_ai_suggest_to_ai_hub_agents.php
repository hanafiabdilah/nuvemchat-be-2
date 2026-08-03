<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Respond with AI" v3: reply suggestions now run on the AI Hub agents —
     * the same agents flow AIAgent nodes use — instead of a separate roster
     * of per-tenant provider keys. `connections.ai_suggest_agent_id` is
     * repointed to `ai_hub_agents` and the old `ai_suggest_agents` table is
     * dropped.
     */
    public function up(): void
    {
        // Old links reference the dropped roster — ids mean nothing in
        // ai_hub_agents, so clear them before swapping the constraint.
        // Tenants relink on the Connections page.
        DB::table('connections')->update(['ai_suggest_agent_id' => null]);

        Schema::table('connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_suggest_agent_id');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->foreignId('ai_suggest_agent_id')
                ->nullable()
                ->after('closing_message')
                ->constrained('ai_hub_agents')
                ->nullOnDelete();
        });

        Schema::drop('ai_suggest_agents');

        // The standalone suggest-agent manager is gone (hub agents live on
        // the AI Agent page), so its permission has nothing left to guard.
        // The role pivot rows cascade with the permission row.
        DB::table('permissions')->where('name', 'ai-suggest.settings')->delete();

        try {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable) {
            //
        }
    }

    public function down(): void
    {
        Schema::create('ai_suggest_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider');
            $table->text('api_key');
            $table->string('model')->nullable();
            $table->timestamps();
        });

        DB::table('connections')->update(['ai_suggest_agent_id' => null]);

        Schema::table('connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_suggest_agent_id');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->foreignId('ai_suggest_agent_id')
                ->nullable()
                ->after('closing_message')
                ->constrained('ai_suggest_agents')
                ->nullOnDelete();
        });
    }
};
