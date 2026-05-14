<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_hub_runs', function (Blueprint $table) {
            $table->boolean('handoff_triggered')->default(false)->after('output_message');
            $table->json('handoff_details')->nullable()->after('handoff_triggered');

            $table->index(['ai_hub_agent_id', 'handoff_triggered']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_hub_runs', function (Blueprint $table) {
            $table->dropIndex(['ai_hub_agent_id', 'handoff_triggered']);
            $table->dropColumn(['handoff_triggered', 'handoff_details']);
        });
    }
};
