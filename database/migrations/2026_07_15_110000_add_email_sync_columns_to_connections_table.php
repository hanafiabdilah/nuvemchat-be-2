<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->unsignedBigInteger('last_seen_uid')->default(0)->after('credentials');
            $table->timestamp('last_synced_at')->nullable()->after('last_seen_uid');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['last_seen_uid', 'last_synced_at']);
        });
    }
};
