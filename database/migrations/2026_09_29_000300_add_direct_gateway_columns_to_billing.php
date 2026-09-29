<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing can now go straight to a gateway (PAYMENT_METHOD=direct) as well
     * as through the payment service, so a charge has to remember which one it
     * was made on.
     *
     * `gateway` null means the payment service — every row written before this
     * migration went there, so no backfill is needed and none would be honest.
     * Reading the env instead would send a renewal or a status check to the
     * wrong company the day somebody flips it.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // The gateway that holds `payment_instrument_id`. A stored card is
            // meaningless anywhere else.
            $table->string('gateway', 32)->nullable()->after('payment_method');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('gateway', 32)->nullable()->after('payment_method');

            // A hosted payment page (dLocal Go). The customer is sent there and
            // comes back; the webhook is what settles the invoice.
            $table->text('checkout_url')->nullable()->after('pix_expires_at');
            $table->timestamp('checkout_expires_at')->nullable()->after('checkout_url');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['gateway', 'checkout_url', 'checkout_expires_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('gateway');
        });
    }
};
