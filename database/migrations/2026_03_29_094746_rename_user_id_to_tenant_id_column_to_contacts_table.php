<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tambah kolom tenant_id terlebih dahulu
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });

        // Copy nilai user_id ke tenant_id (karena user_id sudah merefer ke tenant)
        DB::statement('UPDATE contacts SET tenant_id = user_id');

        // Hapus foreign key dan kolom user_id lama
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        // Tambahkan foreign key untuk tenant_id
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tambah kembali kolom user_id
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
        });

        // Copy nilai tenant_id ke user_id
        DB::statement('UPDATE contacts SET user_id = tenant_id');

        // Hapus foreign key dan kolom tenant_id
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        // Tambahkan kembali foreign key untuk user_id
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
