<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `usd_brl_rate` → `usd_rate`.
 *
 * The column records the rate a debit was priced at, so an old charge can still
 * explain itself. It was named for the only currency the platform had; with a
 * balance held in rupiah the same column holds rupiah per dollar, and a column
 * called `usd_brl_rate` carrying 16300 is a lie that will outlive everyone who
 * knows better.
 *
 * Renamed rather than added beside: two columns for one number is two numbers
 * to keep right, and the reader of a 2026 row would have to know which one was
 * filled that month. The value's meaning does not change — units of the row's
 * own currency per US dollar — and `credit_transactions.currency` already says
 * which currency that is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->renameColumn('usd_brl_rate', 'usd_rate');
        });
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->renameColumn('usd_rate', 'usd_brl_rate');
        });
    }
};
