<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer is told in the chat when their call could not be taken:
 * nobody was free to ring, everybody rung declined, or it rang out.
 *
 * Written by the workspace, never by us — the engine does not know the
 * language of the conversation (the same rule as the flow fallbacks). Empty
 * means the call is refused without a message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->text('call_busy_message')->nullable()->after('closing_message');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('call_busy_message');
        });
    }
};
