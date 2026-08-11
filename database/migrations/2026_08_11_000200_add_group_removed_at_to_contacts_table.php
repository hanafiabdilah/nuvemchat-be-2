<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A "removed" group: its inbound messages are dropped at ingest so the
     * thread never reappears in the panel. The flag lives on the group's own
     * Contact row (is_group), which is also the removed-groups list itself —
     * and the reason a removed group keeps its name and photo in sync: the
     * contact carries on being maintained, only the messages stop.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('group_removed_at')->nullable()->after('group_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('group_removed_at');
        });
    }
};
