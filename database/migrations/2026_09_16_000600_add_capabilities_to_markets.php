<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each country may sell and connect.
 *
 * Deliberately **not** backfilled. The column holds only what an admin decided;
 * an unset key falls back to the supplier's own country (MarketCapabilities),
 * so Brazil keeps every product and every Brazilian gateway on the day this
 * ships without a row being written — and a capability added later has an
 * answer everywhere instead of being invisible until somebody ticks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->json('capabilities')->nullable()->after('price_rounding_cents');
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });
    }
};
