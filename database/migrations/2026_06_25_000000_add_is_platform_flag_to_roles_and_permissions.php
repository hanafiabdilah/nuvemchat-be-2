<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flag platform-level (Back Office) roles & permissions so they stay
     * isolated from tenant RBAC, which shares the same `web` guard.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_platform')->default(false)->index();
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->boolean('is_platform')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_platform');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('is_platform');
        });
    }
};
