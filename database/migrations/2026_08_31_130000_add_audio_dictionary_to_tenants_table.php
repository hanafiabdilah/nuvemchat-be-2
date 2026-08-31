<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace's own vocabulary: product names, acronyms and jargon a general
 * transcription model spells phonetically ("SOCKS5" comes back as "socks
 * five", "ProxyBR" as "proxy be erre").
 *
 * On the tenant rather than on a flow node or an agent because that is how the
 * words actually vary: they belong to the business, not to one bot's persona.
 * A node-level list would also be invisible to "Respond with AI", which runs
 * with no node behind it at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('audio_dictionary')->nullable()->after('lead_settings');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('audio_dictionary');
        });
    }
};
