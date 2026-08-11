<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user UI preferences (theme preset + light/dark appearance).
     * Deliberately a single JSON blob: these are cosmetic, never queried, and
     * new keys must not cost a migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('ui_preferences')->nullable()->after('whatsapp_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ui_preferences');
        });
    }
};
