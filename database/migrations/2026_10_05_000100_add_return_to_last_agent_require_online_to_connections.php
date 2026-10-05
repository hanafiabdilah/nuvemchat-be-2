<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether "return to the last agent" needs that agent to be online.
     *
     * On by default, which is the rule every connection already runs under: an
     * assignment to an empty chair is a customer waiting on somebody who is not
     * coming, while the queue is watched by everyone. Some teams would still
     * rather keep the thread with its agent — one person per customer, answered
     * when they are back — and for them a returning contact opening a fresh
     * conversation with the bot ten minutes after being helped is the worse
     * outcome. That is a choice about how the team works, so it sits on the
     * connection beside the switch and the tolerance it qualifies.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->boolean('return_to_last_agent_require_online')->default(true)
                ->after('return_to_last_agent_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('return_to_last_agent_require_online');
        });
    }
};
