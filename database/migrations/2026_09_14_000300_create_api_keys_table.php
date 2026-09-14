<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials for the public API (/api/v1/*).
 *
 * One kind of key, owned by the workspace: a request names the connection it
 * acts on (`connection_id`, the connection's public id) rather than being
 * scoped to one by its key. So a key has to be nameable ("ProxyBR", "Site"),
 * revocable one integration at a time, and never readable again after it is
 * shown — only its SHA-256 is stored. The keys are 48 random characters, so a
 * plain hash is enough; a slow hash would buy nothing against a secret that
 * cannot be guessed and would cost a bcrypt on every request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
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
        Schema::dropIfExists('api_keys');
    }
};
