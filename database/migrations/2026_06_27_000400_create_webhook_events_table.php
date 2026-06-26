<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw inbound payment-provider webhook log — idempotency + replay/audit.
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('mercadopago');
            $table->string('event_type')->nullable();
            $table->string('resource_id')->nullable()->index();
            $table->boolean('signature_valid')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('dedupe_key')->unique(); // {type}:{dataId}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
