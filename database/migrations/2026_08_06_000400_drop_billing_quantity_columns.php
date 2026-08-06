<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The quantity-based "WhatsApp API" plan flow is replaced by API Way instance
 * purchases through the ProxyBR partner API (apiway_subscriptions). Removed
 * pre-production, so no data migration is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('quantity_enabled');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('quantity_enabled')->default(false);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1);
        });
    }
};
