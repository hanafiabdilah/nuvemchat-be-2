<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliates: a user the Back Office turns into an affiliator gets a link
 * (/r/{code}). A visitor who opens it is locked to that affiliator until they
 * sign up, and the workspace they create stays locked to them for good
 * (tenants.referred_by_affiliate_id). Every paid plan charge of that workspace
 * (the plan and its extras) earns the affiliator a percentage — on the first
 * one only, or on every one for N months / forever.
 *
 * Commissions are paid by hand: each one waits a holding period (so a refund
 * can still reverse it), then an admin pays what is available and records the
 * payout here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            // Null once the user is deleted: what they earned stays on record.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            // The link's /r/{code}. Stored lower case.
            $table->string('code', 32)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('commission_percent');
            // once = the referred workspace's first paid plan charge only;
            // recurring = every one, for `recurring_months` (null = forever).
            $table->string('commission_type', 16)->default('recurring');
            $table->unsignedSmallInteger('recurring_months')->nullable();
            // Where the affiliator wants to be paid (a Pix key, bank details).
            // Written by the affiliator, read by whoever pays.
            $table->json('payout_details')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            // A keyed hash of the address, only to count one visitor once a day.
            $table->string('visitor_hash', 64);
            $table->date('day');
            $table->timestamp('created_at')->nullable();
            $table->unique(['affiliate_id', 'visitor_hash', 'day']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('referred_by_affiliate_id')->nullable()->constrained('affiliates')->nullOnDelete();
            $table->timestamp('referred_at')->nullable();
        });

        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            // How it was paid (pix, bank transfer, …) and its receipt/reference.
            $table->string('method', 32);
            $table->string('reference', 191)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            // One commission per paid charge — the idempotency of the whole thing.
            // Null on a clawback row (see reversal_of_id).
            $table->foreignId('invoice_id')->nullable()->unique()->constrained('invoices')->nullOnDelete();
            // A refund of a commission already paid out: a negative row that the
            // next payout deducts.
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('affiliate_commissions')->nullOnDelete();
            $table->bigInteger('base_cents');
            $table->unsignedTinyInteger('percent');
            // Signed: a clawback is negative.
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            // pending (holding or available — see available_at) | paid | reversed
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('available_at')->nullable()->index();
            $table->foreignId('payout_id')->nullable()->constrained('affiliate_payouts')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['affiliate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_payouts');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_affiliate_id');
            $table->dropColumn('referred_at');
        });

        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliates');
    }
};
