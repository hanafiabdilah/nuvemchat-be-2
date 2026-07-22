<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Drops the now-unused `facebook.redirect_uri` setting.
 *
 * WhatsApp Embedded Signup returns a code that is not issued against a redirect
 * URI, so the token exchange never sends one — the value was dead configuration
 * (and, if filled in, would have made Meta reject the exchange as a mismatch).
 * Instagram's `instagram.redirect_uri` is a real OAuth redirect and stays.
 */
return new class extends Migration
{
    private const KEY = 'facebook.redirect_uri';

    public function up(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();

        // Setting::get() caches forever, so a stale entry would outlive the row.
        Cache::forget('setting:' . self::KEY);
    }

    public function down(): void
    {
        // Nothing to restore: the value was write-only dead config, and no code
        // reads this key any more.
    }
};
