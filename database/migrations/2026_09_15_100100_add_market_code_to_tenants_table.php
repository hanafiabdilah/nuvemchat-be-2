<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every workspace belongs to exactly one market, from the moment it exists.
 *
 * Backfilled to Brazil — the only country the platform has sold in — and then
 * made required, so a workspace without a market is something the database
 * refuses rather than a null every price and currency lookup has to guess
 * around. Restrict on delete: a market with workspaces is paused, never removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('market_code', 2)->nullable()->after('user_id');
        });

        DB::table('tenants')->whereNull('market_code')->update(['market_code' => 'BR']);

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('market_code', 2)->nullable(false)->change();
            $table->foreign('market_code')->references('code')->on('markets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['market_code']);
            $table->dropColumn('market_code');
        });
    }
};
