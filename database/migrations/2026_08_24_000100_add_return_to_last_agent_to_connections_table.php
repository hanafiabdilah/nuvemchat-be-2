<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Return to the last agent": a customer who comes back shortly after their
     * conversation was closed reaches the person who was already helping them,
     * instead of being greeted by the chatbot as a stranger and queued behind
     * everyone else.
     *
     * Off by default. It changes where inbound conversations land — a workspace
     * that never asked for it must keep the routing it has today.
     *
     * The tolerance is stored beside the switch rather than in one global
     * setting: it is a property of the channel's traffic, not of the tenant. A
     * support number where people reply within a minute and a marketing number
     * where they come back the next morning want different numbers, and the
     * connection is where both are already configured.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->boolean('return_to_last_agent')->default(false)->after('closing_message');

            // Minutes counted from when the previous conversation closed. 15 is
            // the value the switch is born with; it only matters once the
            // switch is on.
            $table->unsignedSmallInteger('return_to_last_agent_minutes')->default(15)
                ->after('return_to_last_agent');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['return_to_last_agent', 'return_to_last_agent_minutes']);
        });
    }
};
