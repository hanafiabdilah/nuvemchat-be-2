<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An exclusive conversation is readable only by whoever is handling it and by
 * the workspace's owners. Everyone else with the connection still sees the row
 * in the inbox (contact, status, assignee) but never its content.
 *
 * A timestamp rather than a boolean, plus who switched it on: "since when has
 * this been hidden, and by whom" is the first question somebody asks when an
 * attendant cannot open a thread they expected to read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('exclusive_at')->nullable()->after('muted_at');
            $table->foreignId('exclusive_by_user_id')->nullable()->after('exclusive_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exclusive_by_user_id');
            $table->dropColumn('exclusive_at');
        });
    }
};
