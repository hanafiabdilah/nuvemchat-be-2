<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The index behind `GET /messages/search`.
 *
 * Search used to run in the browser over every message the device had
 * downloaded. Moving it to the server without an index would only move the
 * cost: `body LIKE '%term%'` over millions of rows is a full table scan per
 * keystroke's worth of debounce.
 *
 * ⚠️ DEPLOY: adding the FIRST FULLTEXT index to an InnoDB table is NOT an
 * online operation — InnoDB has to add its internal FTS_DOC_ID column, which
 * rebuilds the table and blocks writes for the duration. On a `messages` table
 * of millions of rows that is minutes, and during it every inbound webhook
 * that tries to store a message waits. Run it in a maintenance window, or
 * build it out of band (percona-online-schema-change / `ALTER ... ALGORITHM
 * =INPLACE` on a replica, then promote). It is written as its own migration so
 * it can be run separately from the rest of the deploy:
 *
 *   php artisan migrate --path=database/migrations/2026_09_07_000400_add_fulltext_index_to_messages_body.php
 *
 * SQLite (the test suite) has no equivalent, and does not need one: the search
 * service falls back to LIKE there. See App\Services\Message\MessageSearch.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Both spellings: Laravel 11 split MariaDB out into its own driver, and
        // which one this deployment reports depends on DB_CONNECTION alone.
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->fullText('body');
        });
    }

    public function down(): void
    {
        // Both spellings: Laravel 11 split MariaDB out into its own driver, and
        // which one this deployment reports depends on DB_CONNECTION alone.
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropFullText(['body']);
        });
    }
};
