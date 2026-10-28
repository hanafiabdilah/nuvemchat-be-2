<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A video composed in Pingly, until it is on YouTube.
     *
     * Like `instagram_posts`, this is not a mirror of the channel: what is live
     * is read from YouTube on every request. What lives here is what YouTube
     * has no concept of — a draft, an upload on its way, and why one failed.
     *
     * One difference from Instagram shapes the columns: YouTube schedules
     * natively. A scheduled video is uploaded straight away as private with
     * `publishAt`, and YouTube flips it public on time, so `scheduled_at` is
     * metadata sent with the upload rather than a timer we keep. Once the
     * upload lands the row is a receipt (`yt_video_id`) and the video is
     * managed as a YouTube video from then on.
     */
    public function up(): void
    {
        Schema::create('youtube_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('youtube_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('draft');

            // YouTube's own ceilings: 100 characters, 5000 bytes.
            $table->string('title', 100)->nullable();
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->string('category_id', 8)->nullable();
            // public | unlisted | private — what the video should be once it
            // is out. A schedule always lands as public at `scheduled_at`.
            $table->string('privacy_status')->default('public');
            $table->boolean('made_for_kids')->default(false);
            $table->boolean('notify_subscribers')->default(true);

            // Our copy of the file, on the private media disk. Deleted once
            // YouTube has it: storage is the one cost that only goes up, and
            // the bytes now live on YouTube.
            $table->string('video_path')->nullable();
            $table->string('video_name')->nullable();
            $table->unsignedBigInteger('video_size')->nullable();
            $table->string('video_mime')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('yt_video_id')->nullable();

            // The resumable session. Kept so an upload a worker abandoned
            // resumes from the last byte YouTube confirmed instead of starting
            // over — and so a retry never creates a second video.
            $table->text('upload_url')->nullable();
            $table->unsignedBigInteger('uploaded_bytes')->default(0);

            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamps();

            $table->index(['youtube_account_id', 'status']);
            $table->index(['status', 'updated_at']);
            $table->index('yt_video_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_posts');
    }
};
