<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A YouTube channel a workspace has connected.
     *
     * Deliberately NOT a row in `connections`. That table is the inbox: every
     * row there is offered as a filter in the conversation panel, counted in
     * the connection quota, shown in statistics and given flows, and YouTube
     * carries no conversations at all — so the channel is connected from the
     * YouTube page and lives here.
     *
     * The identity columns are kept out of `credentials` on purpose: the
     * account list is drawn from them without decrypting a token per row, and
     * `channel_id` is what the unique key and the revoke guard match on.
     */
    public function up(): void
    {
        Schema::create('youtube_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Who pressed Connect. Kept for support ("whose Google account is
            // this?"); the channel outlives the person.
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('channel_id');
            $table->string('title');
            $table->string('handle')->nullable();
            $table->string('thumbnail_url', 1024)->nullable();
            // "UU" + the channel id, but read from Google rather than derived:
            // it is the playlist the feed is read from.
            $table->string('uploads_playlist_id')->nullable();

            // access_token, refresh_token, token_expires_at, scope — encrypted.
            $table->text('credentials');

            // active | reconnect_required. The second is set the moment Google
            // refuses the refresh token, so the page can say "reconnect"
            // instead of failing every call the same opaque way.
            $table->string('status')->default('active');
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();

            $table->timestamps();

            // Reconnecting the same channel updates its row instead of adding a
            // second card for it.
            $table->unique(['tenant_id', 'channel_id']);
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_accounts');
    }
};
