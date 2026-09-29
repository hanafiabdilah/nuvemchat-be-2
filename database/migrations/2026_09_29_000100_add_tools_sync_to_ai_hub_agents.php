<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the AI Hub knows about an agent's tools — see AiToolHubSync.
 *
 * The hub keeps a per-agent tool catalog and a per-agent Pingly credential
 * (PINGLY-TOOLS-20260928.md). Both are registered by us, and both have to be
 * remembered here: the catalog by hash, so it is re-sent only when the code's
 * definitions change, and the credential by the API key it carries, because
 * the plain key exists only at the moment it is issued. A revoked key is how
 * we learn it has to be issued and registered again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_hub_agents', function (Blueprint $table) {
            $table->string('tools_catalog_hash', 64)->nullable();
            $table->foreignId('tools_api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_hub_agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tools_api_key_id');
            $table->dropColumn('tools_catalog_hash');
        });
    }
};
