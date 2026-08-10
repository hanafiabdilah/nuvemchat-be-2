<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile photos used to be downloaded once, at contact creation, and never
 * again — a contact (or a group) that changed its picture kept the stale one
 * forever. This stamp is what lets ContactPhotoSyncer re-check on a TTL
 * instead: null = never synced, otherwise the last successful check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('photo_synced_at')->nullable()->after('photo_profile');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('photo_synced_at');
        });
    }
};
