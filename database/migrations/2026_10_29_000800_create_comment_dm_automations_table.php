<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Comentário → Direct": a comment on an Instagram post answered with a private
 * message (and, optionally, a short public reply), so price and link talk moves
 * out of the comments and into the inbox.
 *
 * Rules live per account (connection); every comment a rule acts on leaves one
 * run row — the unique (connection, comment) is what makes a webhook Meta
 * delivers twice a single DM, since Instagram accepts one private reply per
 * comment and a second attempt would only fail.
 *
 * Also: the agent that drafts comment replies on a YouTube channel. Instagram
 * and TikTok accounts are connections and already have one
 * (`connections.ai_suggest_agent_id`); a channel is not, so it gets its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_dm_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('enabled')->default(true);
            // Null = every post, including ones published later.
            $table->json('media_ids')->nullable();
            $table->string('match_mode', 16)->default('keywords');
            $table->json('keywords')->nullable();
            $table->json('public_replies')->nullable();
            $table->text('dm_message');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['connection_id', 'enabled']);
        });

        Schema::create('comment_dm_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->nullable()->constrained('comment_dm_automations')->nullOnDelete();
            $table->string('comment_id', 64);
            $table->string('media_id', 64)->nullable();
            $table->string('commenter_id', 64)->nullable();
            $table->string('commenter_username', 100)->nullable();
            $table->text('comment_text')->nullable();
            $table->string('status', 16);
            $table->string('reason', 48)->nullable();
            $table->string('dm_message_id', 191)->nullable();
            $table->string('public_reply_id', 64)->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'comment_id']);
            $table->index(['connection_id', 'media_id', 'commenter_id']);
            $table->index(['connection_id', 'created_at']);
        });

        Schema::table('youtube_accounts', function (Blueprint $table) {
            $table->foreignId('ai_suggest_agent_id')->nullable()->after('connected_by')
                ->constrained('ai_hub_agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('youtube_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_suggest_agent_id');
        });

        Schema::dropIfExists('comment_dm_runs');
        Schema::dropIfExists('comment_dm_automations');
    }
};
