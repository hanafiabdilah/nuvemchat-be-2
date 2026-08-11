<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a group's metadata (its subject) was last read from the channel.
     * Separate from photo_synced_at: the two come from different endpoints and
     * fail independently, so one backing off must not silence the other.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('group_synced_at')->nullable()->after('photo_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('group_synced_at');
        });
    }
};
