<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every proof of payment a flow asked an AI to read, and what came of it.
     *
     * Two readers. The first is the next check: a receipt is a picture, and the
     * cheapest fraud there is consists of sending the same picture twice, or to
     * two conversations — so an approval looks here for the same file or the
     * same transaction id before it stands. The second is whoever has to
     * explain a sale later: the amount, the names and the model's own answer
     * are kept beside the message they were read from.
     */
    public function up(): void
    {
        Schema::create('flow_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('flow_id')->nullable();
            $table->unsignedBigInteger('flow_node_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->unsignedBigInteger('ai_hub_run_id')->nullable();
            $table->string('status', 20);
            $table->string('reason', 40)->nullable();
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('payer')->nullable();
            $table->string('recipient')->nullable();
            $table->string('paid_on', 20)->nullable();
            $table->string('transaction_id', 120)->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'transaction_id']);
            $table->index(['tenant_id', 'file_hash']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_receipts');
    }
};
