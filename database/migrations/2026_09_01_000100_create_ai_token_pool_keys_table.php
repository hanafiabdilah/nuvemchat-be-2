<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own pool of provider API keys, rented out to tenants who do
 * not want to bring their own.
 *
 * This is the first table in the product that stores a raw provider secret.
 * Everywhere else a key is write-only: the tenant types it, it is forwarded to
 * the AI hub, and all that comes back (and all that is kept) is a preview. Here
 * the key has to survive, because the same key is registered into the hub scope
 * of every tenant renting it — a hub credential belongs to a hub tenant, so
 * there is no single shared record to point at. The column is encrypted at the
 * model, must never reach a Resource, and must never be logged.
 *
 * `weight` and `max_tenants` exist because sharing is the whole point and also
 * the whole risk: a provider's rate limit is per organisation, so one busy
 * workspace on a small key throttles everyone else sharing it. `max_tenants`
 * caps how many can land on one key; `weight` lets an admin push traffic
 * towards the keys that can take it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_token_pool_keys', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('label');
            $table->text('api_key');
            $table->string('key_preview')->nullable();
            $table->string('default_model')->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('weight')->default(1);
            $table->unsignedInteger('max_tenants')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_token_pool_keys');
    }
};
