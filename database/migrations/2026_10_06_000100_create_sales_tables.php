<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a workspace sold through its conversations, and which ad brought
     * each customer in.
     *
     * `sales` is a ledger of its own rather than a query over the three places
     * money is confirmed (a receipt read by an AI, a gateway charge, an order):
     * each of those has its own shape, its own idea of "when", and its own
     * future, and a sales page that unions them has to know all three forever.
     * A row is written once, at the moment the sale is confirmed, with what the
     * flow's author said about it (front offer or upsell, which offer) and the
     * ad the customer arrived from.
     *
     * `ad_referrals` holds the ad a conversation started from. WhatsApp hands
     * it over on the customer's first message and never again, and it lives in
     * a JSON payload that is not something a report can group by.
     *
     * `tenants.sales_goal` is the revenue target the sales page draws its
     * progress bar against.
     */
    public function up(): void
    {
        Schema::create('ad_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('platform', 20)->default('meta');
            $table->string('ad_id', 64)->nullable();
            $table->string('source_type', 20)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('title')->nullable();
            $table->string('ctwa_clid')->nullable();
            $table->timestamp('referred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'referred_at']);
            $table->index(['tenant_id', 'ad_id']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('connection_id')->nullable()->index();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('source', 20);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('kind', 20)->nullable();
            $table->string('offer', 120)->nullable();
            $table->string('ad_id', 64)->nullable();
            $table->timestamp('sold_at');
            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->index(['tenant_id', 'sold_at']);
            $table->index(['tenant_id', 'ad_id']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->json('sales_goal')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('sales_goal');
        });

        Schema::dropIfExists('sales');
        Schema::dropIfExists('ad_referrals');
    }
};
