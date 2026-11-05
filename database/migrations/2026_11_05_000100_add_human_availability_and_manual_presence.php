<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two halves of one question: "is there a person to hand this customer to?"
 *
 * - `connections.human_availability` — what answers it on this connection.
 *   `service_hours` (the default) is the rule every connection ran under before
 *   this was a choice: the schedule decides, whoever is signed in. The other
 *   value, `online_agents`, ignores the schedule and asks whether anybody who
 *   can open this connection is online right now.
 *
 * - `users.offline_since` — the agent's own Online/Offline switch. Null is
 *   Online, which is also what every existing row reads as, so nobody goes
 *   offline because of a deploy. Signing in clears it again (AuthController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('human_availability', 20)->default('service_hours')->after('service_hours');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('offline_since')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('human_availability');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('offline_since');
        });
    }
};
