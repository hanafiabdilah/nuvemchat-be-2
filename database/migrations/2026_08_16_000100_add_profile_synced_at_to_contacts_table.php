<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Name and username used to be read once, at contact creation, and never
 * again. On Instagram that is exactly the moment the lookup cannot work: when
 * the business writes first, the person has not messaged us yet, so the User
 * Profile API refuses and the contact keeps its numeric id as a name forever —
 * including after they reply, when the lookup would finally succeed.
 *
 * This stamp is what lets ContactProfileSyncer try again without firing one
 * doomed request per inbound message: null = never looked up, otherwise the
 * last attempt (successful or not).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('profile_synced_at')->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('profile_synced_at');
        });
    }
};
