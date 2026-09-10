<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External apps a workspace connects its own accounts to: payment gateways and
 * tracking pixels today, whatever comes next tomorrow.
 *
 * Not the platform's `settings` table. Those are credentials the platform owns
 * and one operator manages; these belong to a customer, are entered by the
 * customer, and move the customer's money or report to the customer's ad
 * account. A workspace may hold several of the same provider — two stores, two
 * pixels — which is why a row has a `name` and why nothing is unique on
 * (tenant, provider).
 *
 * `webhook_token` is the route key of the provider's callback URL. Random and
 * unique, because the URL is the only thing identifying the account an
 * unauthenticated webhook is about — and it is registered at the provider, so
 * it must not be derivable from an id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('name', 120);

            // Encrypted JSON (the model casts it). Text rather than json: the
            // column holds ciphertext, which no JSON type accepts.
            $table->text('credentials')->nullable();

            // The non-secret half of the form (pixel id, sandbox flag, default
            // payer e-mail). Readable by the builder.
            $table->json('settings')->nullable();

            // What we learned from the provider: account name, the ids of the
            // webhooks we registered there.
            $table->json('meta')->nullable();

            $table->boolean('enabled')->default(true);
            $table->string('webhook_token', 64)->unique();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
