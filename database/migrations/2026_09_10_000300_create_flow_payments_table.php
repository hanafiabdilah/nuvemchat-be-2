<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every charge a flow's payment node issued, in the workspace's own gateway.
 *
 * The row exists for two readers. The engine: a payment node parks the flow
 * until the gateway says paid or the clock runs out, and this row is what the
 * webhook, the expiry job and the polling sweep all converge on — `status` only
 * ever leaves `pending` once (guarded by a row lock), which is what makes the
 * flow resume exactly once however many of them arrive. And the person: the
 * integration's page lists these, because "did anyone pay through the bot
 * today" should not be a question only the gateway's dashboard can answer.
 *
 * `reference` is ours and is what we send as the gateway's idempotency key /
 * correlation id / external reference — so a retried create cannot become a
 * second charge, and a webhook naming the charge finds this row without
 * trusting anything else in its body.
 *
 * Nothing here is money the platform holds: the charge is in the customer's
 * gateway account and settles there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Nulled rather than cascaded: deleting an integration must not
            // erase the record that somebody paid through it.
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            // Null only for a node whose integration was already gone when it
            // ran — the attempt is still recorded, as failed, with the reason.
            $table->string('provider', 40)->nullable();

            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_state_id')->nullable()->constrained('flow_states')->nullOnDelete();
            $table->foreignId('flow_node_id')->nullable()->constrained('flow_nodes')->nullOnDelete();

            $table->string('reference', 64)->unique();
            $table->string('provider_payment_id', 128)->nullable()->index();

            $table->string('method', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('description', 255)->nullable();

            $table->string('status', 20);

            // What the customer was sent. The Pix code is also what the QR
            // image is drawn from, on request, so no image is ever stored.
            $table->text('pix_code')->nullable();
            $table->text('payment_url')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // The sweep's two questions: which pending ones are overdue, and
            // which have not been checked for a while.
            $table->index(['status', 'expires_at']);
            $table->index(['integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_payments');
    }
};
