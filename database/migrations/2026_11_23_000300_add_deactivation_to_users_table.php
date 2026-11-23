<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agent who no longer works here, kept rather than deleted.
 *
 * Deleting a person takes their name off everything they did: resolved
 * conversations lose their assignee, and the statistics stop crediting them.
 * Deactivating keeps all of that and only ends the person's access: they
 * cannot sign in, nothing routes to them, and they stop counting as a seat.
 * Null is an active account.
 *
 * nullOnDelete on who did it: that person may be removed later, and the
 * answer is then "somebody no longer here", not a broken row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('offline_set_by_user_id');
            $table->foreignId('deactivated_by_user_id')
                ->nullable()
                ->after('deactivated_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by_user_id');
            $table->dropColumn('deactivated_at');
        });
    }
};
