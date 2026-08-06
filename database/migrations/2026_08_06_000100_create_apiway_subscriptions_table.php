<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local mirror of ProxyBR partner subscriptions. ProxyBR provisions and stores
 * prices; all charging happens here (invoices with an apiway purpose). One row
 * per purchase — `quantity` instances hang off it in `apiway_instances`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apiway_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // ProxyBR's subscription id — null until provisioning succeeds.
            $table->unsignedBigInteger('provider_subscription_id')->nullable()->unique();
            // Idempotency key sent to ProxyBR on create ("pingly-apw-{id}").
            $table->string('external_ref', 160)->unique();

            $table->string('source', 30)->index();   // plan_included | unit
            $table->string('cycle', 10);             // mensal | anual
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->unsignedInteger('total_price_cents')->default(0);
            $table->string('location_code', 20);

            // pending_payment | provisioning | active | suspended | expired | cancelled | failed
            $table->string('status', 30)->index();
            $table->timestamp('expires_at')->nullable()->index();

            // Card auto-debit: one MercadoPago preapproval per unit purchase.
            $table->string('mp_preapproval_id')->nullable()->unique();

            $table->timestamp('renewal_reminder_sent_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apiway_subscriptions');
    }
};
