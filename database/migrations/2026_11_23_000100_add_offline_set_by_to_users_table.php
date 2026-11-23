<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who turned an agent's switch to Offline, when it was not the agent.
 *
 * The switch (`offline_since`) stays the agent's: a supervisor on the agent
 * control page can only move it from Online to Offline, never back, and the
 * agent turns it on again from their own menu or by signing in. What a
 * supervisor needs to read on that page is whether "Offline" was the agent's
 * choice or a colleague's — the same word, two different conversations to
 * have. Null means the agent did it (or nobody did: the switch is Online).
 *
 * nullOnDelete: the person who set it may be removed while the agent stays
 * offline, and the answer then is "somebody no longer here", not a broken row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('offline_set_by_user_id')
                ->nullable()
                ->after('offline_since')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offline_set_by_user_id');
        });
    }
};
