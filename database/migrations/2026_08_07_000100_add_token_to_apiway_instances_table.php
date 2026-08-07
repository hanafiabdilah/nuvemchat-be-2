<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instance core token, fetched once from the partner API and stored
     * encrypted — every console operation (QR, status, webhook, send) talks
     * straight to the core with it.
     */
    public function up(): void
    {
        Schema::table('apiway_instances', function (Blueprint $table) {
            $table->text('token')->nullable()->after('provider_instance_id');
        });
    }

    public function down(): void
    {
        Schema::table('apiway_instances', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};
