<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A workspace's Meta ad accounts, and what each ad cost per day.
     *
     * `meta_ad_accounts` holds one row per ad account the person shared in
     * the Facebook login, with the token that reads it (encrypted). The token
     * is repeated on every account of the same login — one login, one token —
     * because an account is what a sync, a failure and a reconnect are about.
     *
     * `meta_ad_insights` is one row per ad per day, in the ad account's own
     * currency and timezone, exactly as Meta reports it. The Sales tab joins
     * it to `ad_referrals` and `sales` on `ad_id`: the ad a click-to-WhatsApp
     * conversation started from, and what that ad cost.
     */
    public function up(): void
    {
        Schema::create('meta_ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('account_id', 32);
            $table->string('name')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->text('access_token');
            $table->string('fb_user_id', 64)->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('status', 24)->default('active');
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'account_id']);
        });

        Schema::create('meta_ad_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_ad_account_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('ad_id', 32);
            $table->string('ad_name')->nullable();
            $table->string('campaign_id', 32)->nullable();
            $table->string('campaign_name')->nullable();
            $table->unsignedBigInteger('spend_cents')->default(0);
            $table->char('currency', 3);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();

            $table->unique(['meta_ad_account_id', 'ad_id', 'date']);
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'ad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_insights');
        Schema::dropIfExists('meta_ad_accounts');
    }
};
