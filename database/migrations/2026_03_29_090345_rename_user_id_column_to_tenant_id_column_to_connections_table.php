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
        Schema::table('connections', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });

        // Isi tenant_id berdasarkan user_id yang ada
        DB::statement('
            UPDATE connections
            SET tenant_id = (
                SELECT tenants.id
                FROM tenants
                WHERE tenants.user_id = connections.user_id
                LIMIT 1
            )
        ');

        // Hapus foreign key dan kolom user_id lama
        Schema::table('connections', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        // Tambahkan foreign key untuk tenant_id
        Schema::table('connections', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Tambah kembali kolom user_id
        Schema::table('connections', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
        });

        // Isi user_id berdasarkan tenant_id yang ada
        DB::statement('
            UPDATE connections
            SET user_id = (
                SELECT tenants.user_id
                FROM tenants
                WHERE tenants.id = connections.tenant_id
                LIMIT 1
            )
        ');

        // Hapus foreign key dan kolom tenant_id
        Schema::table('connections', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        // Tambahkan kembali foreign key untuk user_id
        Schema::table('connections', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
