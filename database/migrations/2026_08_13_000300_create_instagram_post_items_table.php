<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The media attached to a post — one row for a single image or video, two
     * to ten for a carousel. Single-media posts get a row too rather than
     * columns on the parent, so the publisher has one shape to walk.
     *
     * `url` is the public URL Meta will fetch. That is not an implementation
     * detail we can hide: the content publishing API takes no uploaded bytes for
     * images, it takes an address and downloads from it, so the file has to be
     * readable by Meta for as long as the container is being built. `path` is
     * our copy of where it sits on disk, so a cancelled or failed post can give
     * the storage back.
     */
    public function up(): void
    {
        Schema::create('instagram_post_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_post_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('position')->default(0);
            // image | video — the child's own kind, which for a carousel may
            // differ from the parent's (a carousel mixes both).
            $table->string('media_type');
            $table->string('url', 2048);
            $table->string('path')->nullable();

            // Carousel children are containers in their own right and are
            // created before the parent; kept so a retry can tell which children
            // already exist at Meta.
            $table->string('ig_container_id')->nullable();

            $table->timestamps();

            $table->index(['instagram_post_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_post_items');
    }
};
