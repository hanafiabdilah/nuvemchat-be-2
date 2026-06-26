<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One usable subscription per tenant (history kept). Entitlements are
     * snapshotted so that a super-admin editing a plan does not silently
     * change the access of already-subscribed tenants.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status')->index(); // trialing|active|past_due|grace|suspended|cancelled|manual
            $table->string('payment_method')->nullable(); // card|pix|manual
            $table->string('billing_cycle')->nullable();   // snapshot of plan cycle at subscribe time

            $table->unsignedInteger('price_cents')->default(0); // snapshot
            $table->json('quotas_snapshot')->nullable();
            $table->json('features_snapshot')->nullable();

            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();

            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();

            $table->string('mp_preapproval_id')->nullable()->index();

            // Comp/manual grant audit.
            $table->foreignId('manual_granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('manual_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
