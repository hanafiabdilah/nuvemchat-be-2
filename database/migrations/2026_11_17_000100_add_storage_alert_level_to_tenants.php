<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last storage warning a workspace was sent: 0, 80, 95 or 100 (%).
 *
 * Every file the workspace stores counts against its storage since Nov 2026,
 * and a full storage refuses uploads — so the owner is told on the way up, once
 * per threshold (storage:check-alerts). This column is what makes it once: the
 * hourly pass compares the level it measures with the one it last announced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedTinyInteger('storage_alert_level')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('storage_alert_level');
        });
    }
};
