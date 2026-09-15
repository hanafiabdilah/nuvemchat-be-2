<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhooks: Pingly telling a workspace's own systems that something
 * happened to a lead (lead.assigned, lead.stage_changed, lead.won, lead.lost).
 *
 * The secret is stored encrypted, not hashed: unlike an API key, which Pingly
 * only has to recognise, a signing secret has to be used on every delivery.
 *
 * Every attempt's outcome lives on the delivery row — what was sent, what came
 * back, how many tries — because "the hub never got the event" is a support
 * conversation, and production does not keep application logs across deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->json('events');
            $table->text('secret');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_delivery_at')->nullable();
            $table->unsignedSmallInteger('last_response_status')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            // Same for every endpoint the event went to, and for every retry:
            // the receiver's deduplication key.
            $table->string('event_id', 40)->index();
            $table->string('event', 64);
            // The exact bytes that were signed. Kept as text so a retry signs
            // and sends the same body, not a re-encoding of it.
            $table->longText('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
