<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags that stay with the person instead of the thread.
 *
 * `conversation_tags` already exists and is untouched: a tag on a conversation
 * says something about that conversation ("orçamento", "reclamação") and is
 * meant to end with it. This table answers the other question — what is true
 * about this customer whatever they happen to be writing about today ("VIP",
 * "revenda") — which the old table could never hold, because a resolved thread
 * takes its tags with it and the next message opens an unlabelled one.
 *
 * Same `tags` rows on both sides, deliberately. A workspace maintaining two
 * vocabularies for the same words would have to pick the right list before it
 * could pick the right tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Unlike conversation_tags, which has no such guard: sync() is the
            // only writer today, but a tag applied twice is not a thing a
            // person can mean, and the pair is what every read filters on.
            $table->unique(['contact_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tags');
    }
};
