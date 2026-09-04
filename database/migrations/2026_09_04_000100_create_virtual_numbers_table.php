<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virtual numbers rented from API Way and resold to tenants.
 *
 * One row per number, per month-to-month subscription. `cost_cents` is what API
 * Way charges the platform and `price_cents` what the tenant paid; both are
 * copied onto the row at purchase rather than recomputed, because the catalog
 * and the markup both move and an old charge must keep meaning what it meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // API Way's id — null only while a purchase is in flight or after
            // one failed. It is the handle for reading SMS and cancelling.
            $table->unsignedBigInteger('provider_number_id')->nullable()->unique();

            $table->string('msisdn', 20)->nullable()->index();
            $table->string('app', 40)->index();
            $table->string('ddd', 4);
            $table->string('region', 80)->nullable();

            $table->string('status', 20)->index();

            $table->unsignedInteger('cost_cents')->default(0);
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('BRL');

            $table->timestamp('purchased_at')->nullable();
            // Next upstream renewal. The day the platform is billed again, so
            // also the deadline for charging the tenant or cancelling.
            $table->timestamp('renews_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('renewal_reminder_sent_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            // Surfaced in the list so "waiting for the code" is visible without
            // opening every number.
            $table->timestamp('last_message_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_numbers');
    }
};
