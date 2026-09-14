<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One lead handed to Pingly by another system through POST /api/v1/leads.
 *
 * The unique (tenant_id, reference) is the idempotency key. The callers this
 * endpoint exists for are schedulers ("sign-ups that did not pay within 3
 * hours"), and a scheduler that times out retries — without this, the retry
 * sends the prospect a second WhatsApp message. `result` is the response the
 * first call produced, replayed verbatim to every retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('reference', 191)->nullable();
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('opening_status', 20)->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_intakes');
    }
};
