<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a card subscription renews by itself.
     *
     * True is what every card subscription has always meant: a card we keep
     * (or a gateway's own schedule) charged each cycle with nobody at a
     * screen. False is a card paid by hand — each cycle raises an invoice and
     * the customer pays it with a saved card and its security code, the way a
     * Pix subscription raises a QR.
     *
     * Defaults to true, so every row from before this choice existed keeps
     * renewing exactly as it did. Meaningless for Pix, a hosted checkout or a
     * manual grant: those never renewed on their own.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(true)->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('auto_renew');
        });
    }
};
