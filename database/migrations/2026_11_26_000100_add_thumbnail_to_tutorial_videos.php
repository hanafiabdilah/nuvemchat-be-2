<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A thumbnail the admin chose for a tutorial video, beside the poster ffmpeg
 * draws from the video itself. Two columns, not one: the chosen one wins while
 * it exists, and removing it brings the drawn one back without re-encoding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutorial_videos', function (Blueprint $table) {
            $table->string('thumbnail_path', 500)->nullable()->after('poster_path');
        });
    }

    public function down(): void
    {
        Schema::table('tutorial_videos', function (Blueprint $table) {
            $table->dropColumn('thumbnail_path');
        });
    }
};
