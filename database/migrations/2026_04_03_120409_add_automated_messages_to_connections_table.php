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
        Schema::table('connections', function (Blueprint $table) {
            $table->text('welcoming_message')->nullable()->after('credentials');
            $table->text('accept_message')->nullable()->after('welcoming_message');
            $table->text('closing_message')->nullable()->after('accept_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['welcoming_message', 'accept_message', 'closing_message']);
        });
    }
};
