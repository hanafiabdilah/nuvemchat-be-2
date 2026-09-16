<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a converted price is rounded up to in this market.
 *
 * Prices that are not set per country are converted from the platform's own
 * (an API Way instance, a gigabyte of gallery storage, an AI run), and a
 * conversion lands on Rp 148.637 — a number no one has ever put on a price
 * tag. The step turns it into Rp 149.000.
 *
 * In minor units, like every other amount here: 100000 is Rp 1.000. Default 1,
 * which rounds nothing — the right answer for Brazil, where the prices are
 * already the ones the platform set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->unsignedBigInteger('price_rounding_cents')->default(1)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn('price_rounding_cents');
        });
    }
};
