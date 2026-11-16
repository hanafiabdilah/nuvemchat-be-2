<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons: a percentage off a plan's charges, or a bonus on a balance top-up.
 *
 * There is deliberately no redemptions table. Who used a coupon, how many
 * workspaces have, and how many cycles a subscription has left are all read
 * off the invoices that carry `coupon_id` — the same rows the money moved on.
 * A counter kept beside them is a second account of the same facts, and the
 * day the two disagree nobody can say which is right.
 *
 * `subscriptions.coupon_terms` is the coupon as it was when the workspace took
 * it (percentage, duration, whether extras are included, the plan/cycle
 * limits). Editing the coupon later never changes what a running
 * subscription was promised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            // Stored upper case: a customer types "black20", the row says BLACK20.
            $table->string('code', 32)->unique();
            // Shown to the customer next to the code.
            $table->string('name', 80);
            $table->string('scope', 16)->index();
            $table->unsignedTinyInteger('percent_off');
            $table->string('duration', 16)->default('once');
            $table->unsignedSmallInteger('duration_cycles')->nullable();
            // A plan coupon only: the extras (agents, numbers, storage) are
            // discounted by the same percentage, or charged at full price.
            $table->boolean('applies_to_addons')->default(false);
            // Listed to every customer on checkout, rather than typed.
            $table->boolean('is_public')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // The quota. A plan coupon counts workspaces (a 3-cycle coupon used
            // by one workspace is one); a top-up coupon counts top-ups, since
            // one workspace may use it on many.
            $table->unsignedInteger('max_redemptions')->nullable();
            // Null = no limit on that axis.
            $table->json('plan_ids')->nullable();
            $table->json('billing_cycles')->nullable();
            $table->json('market_codes')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('plan_id')->constrained('coupons')->nullOnDelete();
            $table->json('coupon_terms')->nullable()->after('coupon_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('subscription_id')->constrained('coupons')->nullOnDelete();
            // A plan invoice: what the coupon took off amount_cents.
            $table->unsignedBigInteger('coupon_discount_cents')->nullable()->after('proration_credit_cents');
            // A top-up invoice: the bonus credited on top of amount_cents.
            $table->unsignedBigInteger('coupon_bonus_cents')->nullable()->after('coupon_discount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_discount_cents', 'coupon_bonus_cents']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('coupon_terms');
        });

        Schema::dropIfExists('coupons');
    }
};
