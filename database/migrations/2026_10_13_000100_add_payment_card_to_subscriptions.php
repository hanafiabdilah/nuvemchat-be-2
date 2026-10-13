<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which card renews a subscription, in the only form that is safe to keep
     * and useful to show: `{brand, last_four, exp_month, exp_year}`.
     *
     * A card subscription kept at Stripe is not one of the workspace's saved
     * cards (those are Mercado Pago's, for top-ups), so without this the plan
     * could only say "renews automatically" and never with what.
     *
     * Null for every subscription made before this, and for gateways that do
     * not report the card: the plan then says nothing rather than guessing.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('payment_card')->nullable()->after('payment_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('payment_card');
        });
    }
};
