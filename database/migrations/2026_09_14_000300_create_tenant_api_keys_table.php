<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials for the workspace-level public API (POST /api/v1/leads, …).
 *
 * Not a column on `tenants` and not the per-connection `connections.api_key`:
 * a workspace key reaches every connection, so it has to be nameable ("ProxyBR",
 * "Site"), revocable one integration at a time, and never readable again after
 * it is shown — only its SHA-256 is stored. The keys are 48 random characters,
 * so a plain hash is enough; a slow hash would buy nothing against a secret
 * that cannot be guessed and would cost a bcrypt on every request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('key_hash', 64)->unique();
            // First and last characters, for telling two keys apart on screen.
            $table->string('hint', 32);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            // Kept rather than deleted: lead_intakes still name the key they came in on.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_api_keys');
    }
};
