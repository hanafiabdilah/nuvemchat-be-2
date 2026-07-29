<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Respond with AI" reply suggestions: the feature is toggled per
     * connection, while the provider credentials (openai/gemini/anthropic)
     * live once per tenant as an encrypted JSON blob.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->boolean('ai_suggest_enabled')->default(false)->after('closing_message');
        });

        Schema::table('tenants', function (Blueprint $table) {
            // {provider, api_key, model} — encrypted via the model cast.
            $table->text('ai_suggest_config')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('ai_suggest_enabled');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('ai_suggest_config');
        });
    }
};
