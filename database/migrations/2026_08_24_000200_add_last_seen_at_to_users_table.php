<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this user's dashboard last reported in.
     *
     * Nothing in the product knew whether an agent was at their desk: a Sanctum
     * token says someone signed in once, and a socket subscription is a fact
     * about Reverb that no other process can read. "Return to the last agent"
     * is the first feature that has to answer the question — handing a
     * returning customer to someone who closed their laptop an hour ago is
     * worse than sending them through the bot, because nobody is coming.
     *
     * Fed by an explicit heartbeat from the SPA rather than by request traffic:
     * an agent reading a long thread makes no requests for minutes at a time
     * and is very much present, while a stale token makes requests from a tab
     * nobody is looking at.
     *
     * Null means "never reported" — which reads as offline, so a deployment
     * whose frontend has not shipped the heartbeat yet simply never routes
     * anyone this way.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
