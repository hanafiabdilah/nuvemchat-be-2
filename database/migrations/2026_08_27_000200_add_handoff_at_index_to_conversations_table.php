<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `handoff_at` gained a reader.
     *
     * Until the live monitor it was only ever written and read back on the one
     * conversation that owned it, so it needed no index. The activity lane asks
     * a different question — "which threads were handed to a person in the last
     * fifteen minutes" — which without this is a range scan of every
     * conversation on the platform, repeated for every open board.
     *
     * `resolved_at` was already indexed when it was added, for the same reason
     * on the statistics side.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index('handoff_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['handoff_at']);
        });
    }
};
