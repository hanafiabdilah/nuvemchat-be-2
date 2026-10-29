<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Read and starred marks on comments from Instagram, YouTube and TikTok.
     *
     * None of the three platforms has either concept — a comment is never
     * "read" at Instagram — so these are ours, kept per workspace (the
     * Comments sections are a shared inbox: what one person reads, the team
     * no longer has to). Only a row per comment someone acted on; a comment
     * with no row is unread and unstarred.
     *
     * Keyed by the platform's own comment id: comments themselves are never
     * stored (they live at the platform and change without telling us), so
     * the id is the only thing to hang a mark on.
     */
    public function up(): void
    {
        Schema::create('social_comment_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // instagram | youtube | tiktok
            $table->string('platform', 16);
            // The connection (Instagram, TikTok) or YouTube account the
            // comment belongs to — for scoping and cleanup, not for lookup.
            $table->unsignedBigInteger('account_id');
            $table->string('comment_id', 128);

            $table->timestamp('read_at')->nullable();
            $table->foreignId('read_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starred_at')->nullable();
            $table->foreignId('starred_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'comment_id']);
            $table->index(['tenant_id', 'platform', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_comment_marks');
    }
};
