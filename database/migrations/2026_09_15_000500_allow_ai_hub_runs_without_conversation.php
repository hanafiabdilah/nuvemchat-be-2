<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A run no longer needs a conversation behind it.
 *
 * The audio vocabulary's test bench runs an agent on a recording or a sentence
 * typed in the dashboard — there is no thread, but the run is just as real: it
 * spends provider money, counts against `max_ai_runs` and is billed to the
 * prepaid balance when the key is rented. Not writing the row would make the
 * test bench the one way to run AI that nobody can see or charge for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_hub_runs', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('ai_hub_runs')->whereNull('conversation_id')->delete();

        Schema::table('ai_hub_runs', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable(false)->change();
        });
    }
};
