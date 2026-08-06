<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API Way purchases/renewals are charges without a plan subscription, so
 * invoices grow a `purpose` discriminator, a nullable `subscription_id` and a
 * link to the apiway subscription being paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();

            // subscription | apiway_purchase | apiway_renewal
            $table->string('purpose', 30)->default('subscription')->index();
            $table->foreignId('apiway_subscription_id')->nullable()
                ->constrained('apiway_subscriptions')->nullOnDelete();
            $table->json('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('apiway_subscription_id');
            $table->dropColumn(['purpose', 'meta']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable(false)->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
        });
    }
};
