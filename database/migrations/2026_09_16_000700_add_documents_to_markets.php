<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which tax document each country asks a payer for — an admin's decision.
 *
 * Not backfilled, like `capabilities` beside it: null means "whatever
 * config/markets.php says for this country", which is exactly what every market
 * answered before this column existed. What the column adds is the two things
 * config cannot express — a country whose documents an admin edited, and a
 * country that asks for **no** document at all, which is a stored empty list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->json('documents')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn('documents');
        });
    }
};
