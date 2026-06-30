<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('price_cents');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('quantity_enabled')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('quantity_enabled');
        });
    }
};
