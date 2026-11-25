<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tutorial videos: platform content, managed in the Back Office and shown in
 * the dashboard next to the feature each one explains.
 *
 * - `tutorial_videos`: one file, in one language. Not tenant-scoped — every
 *   workspace sees the same library, filtered by role and country.
 * - `tutorial_video_topics`: where a video shows. One video can belong to many
 *   topics (a video on API keys explains Integrations and Developer alike), and
 *   the order is per topic, because the first video on one page is not the
 *   first video on another.
 * - `tutorial_video_views`: per person, not per workspace — each agent has to
 *   watch for themselves. Drives the "new" marks and the Back Office's counts.
 * - `tutorial_topic_auto_opens`: a topic whose video opens by itself does so
 *   once per person. Unique, so two tabs racing open it once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorial_videos', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('locale', 10)->index();
            $table->boolean('is_active')->default(true);
            // The first auto-open video of a topic opens by itself on the
            // person's first visit to that topic.
            $table->boolean('auto_open')->default(false);
            $table->string('status', 20)->default('processing')->index();
            $table->string('error', 500)->nullable();
            // The file as uploaded, until processing replaces it with `path`.
            $table->string('source_path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('poster_path', 500)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tutorial_video_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutorial_video_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 80);
            $table->unsignedInteger('position')->default(0);

            $table->unique(['tutorial_video_id', 'topic']);
            $table->index(['topic', 'position']);
        });

        Schema::create('tutorial_video_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tutorial_video_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_viewed_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tutorial_video_id']);
        });

        Schema::create('tutorial_topic_auto_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 80);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'topic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_topic_auto_opens');
        Schema::dropIfExists('tutorial_video_views');
        Schema::dropIfExists('tutorial_video_topics');
        Schema::dropIfExists('tutorial_videos');
    }
};
