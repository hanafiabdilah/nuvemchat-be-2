<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The countries the platform sells in, and the domains each is reached on.
 *
 * Brazil is written here rather than by a seeder: deploys only run
 * `migrate --force`, and every existing workspace is about to be pointed at
 * this row by a foreign key. A seeder nobody ran would make that migration fail
 * on production and nowhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table) {
            // ISO 3166-1 alpha-2, used as the key itself: it is what workspaces,
            // prices and log lines will carry, and "BR" reads where "1" doesn't.
            $table->string('code', 2)->primary();
            $table->string('name');
            $table->char('currency', 3);
            $table->string('default_locale', 10);
            $table->string('default_timezone', 64);
            $table->string('phone_country', 4);
            $table->string('status', 16)->default('draft');
            $table->timestamps();
        });

        Schema::create('market_domains', function (Blueprint $table) {
            $table->id();
            $table->string('market_code', 2);
            $table->foreign('market_code')->references('code')->on('markets')->cascadeOnDelete();
            // Stored lower-case without port; see MarketResolver::normalizeHost().
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        DB::table('markets')->insert([
            'code' => 'BR',
            'name' => 'Brasil',
            'currency' => 'BRL',
            'default_locale' => 'pt_BR',
            'default_timezone' => 'America/Sao_Paulo',
            'phone_country' => '55',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('market_domains');
        Schema::dropIfExists('markets');
    }
};
