<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Button-id prefixes that belong to another system sending on the same
     * WhatsApp number (App\Services\Webhook\ExternalAutomationReply).
     *
     * Null on every existing row, which is the rule they already run under:
     * every tap is the connection's own and runs its flow.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->json('ignored_button_prefixes')->nullable()
                ->after('return_to_last_agent_require_online');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('ignored_button_prefixes');
        });
    }
};
