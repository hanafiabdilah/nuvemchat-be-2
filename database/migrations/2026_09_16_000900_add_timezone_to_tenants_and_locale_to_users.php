<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What hour it is for a workspace, and what language a person reads in.
 *
 * Both were platform-wide constants until now, and both were wrong in a way
 * nobody could see from inside Brazil:
 *
 *  - `config('app.timezone')` is 'UTC', so every date the platform printed for
 *    a human ("vence em 20/09") was a UTC date, and a period ending at 02:00
 *    UTC already read as the next day in São Paulo. The same value seeded a
 *    connection's service hours, so "open 08:00" meant 05:00 locally.
 *  - Language lived only in the browser's localStorage, so a person's choice
 *    never followed them to a second device, and the country the workspace
 *    sells in had no say at all.
 *
 * `tenants.timezone` is copied from the market when a workspace is created, not
 * read through it forever — Brazil alone spans four zones, so a country's
 * default is a starting guess, and correcting that default years later must not
 * silently move the business hours and renewal dates of everyone already in it.
 * The backfill below gives existing workspaces the same treatment. Nullable
 * only so rows written before this column exist can still be read; the accessor
 * falls back to the market for them.
 *
 * `users.locale` stays null for everyone: null means "follow the workspace's
 * market", and writing a value here is the user saying otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // IANA identifier ("America/Sao_Paulo", "Asia/Jakarta"). 64 matches
            // markets.default_timezone; the longest real name is under 40.
            $table->string('timezone', 64)->nullable()->after('market_code');
        });

        Schema::table('users', function (Blueprint $table) {
            // i18next key ("pt_BR", "en", "id") — see config('markets.locales').
            $table->string('locale', 10)->nullable()->after('email');
        });

        // Per market rather than one JOIN: the join syntax differs between
        // MySQL and SQLite, and there are a handful of markets at most.
        foreach (DB::table('markets')->get(['code', 'default_timezone']) as $market) {
            DB::table('tenants')
                ->where('market_code', $market->code)
                ->update(['timezone' => $market->default_timezone]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
