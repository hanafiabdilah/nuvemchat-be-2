<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which variant of a split test each conversation was sent to.
     *
     * Two readers. The node itself, which counts the rows to decide where the
     * next conversation goes; and the results panel, which joins them to the
     * conversation's messages and sales to say which variant is winning.
     *
     * Keyed by flow + variant id rather than by node: a node created in an
     * open editor gets a new id on every save until the page is reloaded, so
     * `flow_node_id` is kept as a pointer for debugging and nothing else.
     *
     * `last_message_id` is the newest message in the thread at the moment the
     * conversation was dealt — anything the customer sends after it is a reply
     * to the funnel, not the message that brought them in.
     */
    public function up(): void
    {
        Schema::create('flow_split_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('flow_node_id')->nullable();
            $table->string('variant_id', 64);
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->timestamps();

            $table->index(['flow_id', 'variant_id', 'created_at']);
            $table->index(['conversation_id', 'flow_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_split_assignments');
    }
};
