<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('flow_edges', function (Blueprint $table) {
            // For condition nodes: 'true' or 'false'
            // For other nodes: null (default sequential flow)
            $table->string('condition_value')->nullable()->after('target_node_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flow_edges', function (Blueprint $table) {
            $table->dropColumn('condition_value');
        });
    }
};
