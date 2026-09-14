<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inbox sends ride the campaign engine (rate limit, delivery report, retry)
     * instead of growing a second one, so a campaign now has to say where its
     * recipients came from. Every existing row is a campaign, hence the default.
     *
     * `resolve_after` only means anything for inbox sends: a campaign reaches
     * people who are not in a conversation yet, so there is nothing to close.
     */
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->string('source')->default('campaign')->after('status');
            $table->boolean('resolve_after')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn(['source', 'resolve_after']);
        });
    }
};
