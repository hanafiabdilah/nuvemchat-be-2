<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The message delta sync — `GET /messages?since=` — filters on `updated_at`,
 * and every sign-in, every reconnection and every wake from sleep runs it.
 * The column had no index: on the largest table in the database that is a scan
 * held back only by the LIMIT and by the fact that recent rows happen to sit
 * at the end of the primary key.
 *
 * Plain secondary index, so InnoDB builds it in place (ALGORITHM=INPLACE,
 * LOCK=NONE) — unlike the FULLTEXT one added alongside it, this does not need
 * a maintenance window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
        });
    }
};
