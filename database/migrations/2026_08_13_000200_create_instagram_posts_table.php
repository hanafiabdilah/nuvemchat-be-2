<?php

use App\Enums\Instagram\PostStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A post we compose, and — once it lands — the receipt for it.
     *
     * This table is deliberately NOT a mirror of the account's feed. Published
     * media is read live from Instagram, which owns the likes, the comment
     * counts and the permalink; caching that here would only buy us stale
     * numbers. What lives here is the part Instagram has no concept of: drafts,
     * schedules, and why an attempt failed.
     *
     * `ig_media_id` is therefore the join between the two worlds. It is null
     * until the publish succeeds, and once set the grid prefers Instagram's copy
     * of the post and uses this row only to know the post was ours.
     */
    public function up(): void
    {
        Schema::create('instagram_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('status')->default(PostStatus::Draft->value);
            $table->string('media_type');
            $table->text('caption')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();

            // Meta's ids. The container is kept after publishing purely for
            // support: when a post lands twice or not at all, the container id
            // is the only thing Meta's logs and ours have in common.
            $table->string('ig_container_id')->nullable();
            $table->string('ig_media_id')->nullable();
            $table->string('permalink')->nullable();

            $table->text('error')->nullable();
            // Read by the publisher, not by the queue: a container that expired
            // or a transcode that failed is worth one fresh attempt, but the
            // 100-posts-per-day allowance is not worth burning on a loop.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['connection_id', 'status']);
            // The scheduler's only query: what is due, oldest first.
            $table->index(['status', 'scheduled_at']);
            // Lets the grid ask "was this feed post ours?" for a page of media
            // in one lookup instead of one per tile.
            $table->index('ig_media_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_posts');
    }
};
