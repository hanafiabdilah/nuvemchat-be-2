<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rented key is not a second kind of credential — it is the same row, with a
 * different owner.
 *
 * Agents point at `ai_hub_provider_credentials.id`, and so do the flow node's
 * transcription and voice settings. Giving rented keys their own table would
 * mean every one of those references growing a "which table?" discriminator,
 * for no gain: the mirror row we create in the tenant's hub scope is a genuine
 * credential of theirs, it is simply one the platform pays for and controls.
 *
 * So the pool link lives here, nullable. Null = the tenant's own key, the only
 * case that existed before. Non-null = rented, which is what the guards read:
 * the tenant may select it, but may not edit, re-key or delete it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_hub_provider_credentials', function (Blueprint $table) {
            $table->foreignId('ai_token_pool_key_id')->nullable()->after('ai_hub_tenant_id')
                ->constrained('ai_token_pool_keys')->nullOnDelete();

            // "How many tenants are already on this key?" is asked on every
            // rental, to honour max_tenants.
            $table->index('ai_token_pool_key_id', 'ai_hub_cred_pool_key_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_hub_provider_credentials', function (Blueprint $table) {
            $table->dropIndex('ai_hub_cred_pool_key_idx');
            $table->dropConstrainedForeignId('ai_token_pool_key_id');
        });
    }
};
