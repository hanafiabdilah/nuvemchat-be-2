<?php

use App\Enums\Broadcast\RecipientStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per person a campaign will reach — the campaign's work queue and
     * its delivery report at once.
     *
     * `address` rather than a plain contact_id: recipients arrive both from the
     * contact book and as numbers typed by hand, and the pasted ones have no
     * contact yet (one gets created when the message actually goes out). Storing
     * the normalised address makes the unique key below collapse both sources
     * into a single recipient, so picking a contact and pasting their number
     * cannot message them twice.
     */
    public function up(): void
    {
        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();

            // All three are filled in as the send progresses: the contact when
            // it is resolved or created, the other two once the message lands.
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();

            $table->string('address');
            $table->string('name')->nullable();

            $table->string('status')->default(RecipientStatus::Pending->value);
            // Meta's own words when it refuses, or the reason we never asked.
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(['broadcast_id', 'address']);
            // Claiming the next batch and counting the report both filter on
            // exactly this pair.
            $table->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
    }
};
