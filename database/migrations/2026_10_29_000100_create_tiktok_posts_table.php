<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A TikTok post composed in Pingly: a draft, a schedule, one on its way,
     * or the receipt for one that landed.
     *
     * Like `instagram_posts`, NOT a mirror of the account — what is live is read
     * from TikTok on every request. TikTok has no draft or schedule on this
     * API, so those are ours; the scheduler fires them.
     */
    public function up(): void
    {
        Schema::create('tiktok_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('draft');
            // video | photo — TikTok's two post kinds, each its own endpoint.
            $table->string('media_type');

            // Photo posts carry a title (90) and a caption (4000); video posts
            // only a caption (2200). Both limits are TikTok's.
            $table->string('title', 120)->nullable();
            $table->text('caption')->nullable();

            // Photo posts choose who sees them; the video endpoint only
            // publishes publicly.
            $table->string('privacy_level')->nullable();
            $table->boolean('disable_comment')->default(false);
            $table->boolean('disable_duet')->default(false);
            $table->boolean('disable_stitch')->default(false);
            $table->boolean('is_ai_generated')->default(false);
            // "Promotional content" (your own business) / "Paid partnership".
            $table->boolean('is_brand_organic')->default(false);
            $table->boolean('is_branded_content')->default(false);
            $table->boolean('auto_add_music')->default(false);

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();

            // The publishing task TikTok hands back, polled until it settles.
            // Kept after publishing for support: it is the one id TikTok's logs
            // and ours share.
            $table->string('share_id')->nullable();
            $table->string('tiktok_item_id')->nullable();

            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamps();

            $table->index(['connection_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('tiktok_post_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiktok_post_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            // image | video
            $table->string('media_type');
            // The public URL TikTok fetches — under the verified prefix.
            $table->string('url', 2048);
            $table->string('path')->nullable();
            $table->timestamps();

            $table->index(['tiktok_post_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_post_items');
        Schema::dropIfExists('tiktok_posts');
    }
};
