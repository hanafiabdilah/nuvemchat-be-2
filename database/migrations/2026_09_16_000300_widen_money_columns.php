<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money columns that cannot hold a rupiah price.
 *
 * Every amount here is minor units, and `unsignedInteger` stops at
 * 4,294,967,295 — about 42.9 million major units. In reais that ceiling is
 * unreachable. In rupiah it is Rp 42.9 juta: an annual plan at Rp 500.000/month
 * is Rp 6 juta, comfortable, but a yearly enterprise price or a large top-up
 * walks into it — and MySQL's answer to that is to clamp the value at the
 * maximum, so the platform would charge the wrong number rather than fail.
 *
 * Widened before any market is priced, which is the only cheap moment: these
 * tables are small today and the change is an in-place column widening on
 * MySQL (no table rebuild), but `invoices` only grows.
 *
 * `credit_transactions.amount_cents` is already bigint and signed (it carries
 * refunds), so the ledger itself needs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('price_cents')->default(0)->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('price_cents')->default(0)->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_cents')->change();
        });

        Schema::table('apiway_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_price_cents')->default(0)->change();
            $table->unsignedBigInteger('total_price_cents')->default(0)->change();
        });

        Schema::table('trained_agent_blueprints', function (Blueprint $table) {
            $table->unsignedBigInteger('price_cents')->default(0)->change();
        });

        Schema::table('trained_agent_hires', function (Blueprint $table) {
            $table->unsignedBigInteger('price_cents')->default(0)->change();
        });

        Schema::table('virtual_numbers', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_cents')->default(0)->change();
            $table->unsignedBigInteger('price_cents')->default(0)->change();
        });

        Schema::table('gallery_storage_rentals', function (Blueprint $table) {
            $table->unsignedBigInteger('price_per_gb_cents')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->default(0)->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->default(0)->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('amount_cents')->change();
        });

        Schema::table('apiway_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('unit_price_cents')->default(0)->change();
            $table->unsignedInteger('total_price_cents')->default(0)->change();
        });

        Schema::table('trained_agent_blueprints', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->default(0)->change();
        });

        Schema::table('trained_agent_hires', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->default(0)->change();
        });

        Schema::table('virtual_numbers', function (Blueprint $table) {
            $table->unsignedInteger('cost_cents')->default(0)->change();
            $table->unsignedInteger('price_cents')->default(0)->change();
        });

        Schema::table('gallery_storage_rentals', function (Blueprint $table) {
            $table->unsignedInteger('price_per_gb_cents')->default(0)->change();
        });
    }
};
