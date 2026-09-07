<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where this agent's photo lives on the private disk.
     *
     * A path, never a URL — the same arrangement as `contacts.photo_profile`,
     * and for the same reason: the bytes are served through a signed link that
     * is minted at serialization time, so storing the link would freeze an
     * expiry into the row and leave the file unreachable the moment it lapsed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
