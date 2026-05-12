<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('sent_by_user_id')->nullable()->after('sender_type')->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by_flow_id')->nullable()->after('sent_by_user_id')->constrained('flows')->nullOnDelete();
            $table->foreignId('sent_by_ai_hub_agent_id')->nullable()->after('sent_by_flow_id')->constrained('ai_hub_agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by_user_id');
            $table->dropConstrainedForeignId('sent_by_flow_id');
            $table->dropConstrainedForeignId('sent_by_ai_hub_agent_id');
        });
    }
};
