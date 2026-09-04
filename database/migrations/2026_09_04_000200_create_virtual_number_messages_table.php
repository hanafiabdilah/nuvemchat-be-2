<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS received on a rented number.
 *
 * The same message arrives twice by design: API Way pushes it to the platform
 * webhook and the tenant's screen can also poll `/numbers/{id}/sms`. Neither
 * source carries an id we can rely on — the webhook body has none at all — so
 * `dedupe_key` is a hash of what the message *is* (sender + text + timestamp),
 * unique per number. Two deliveries of one SMS collapse; two genuinely
 * identical SMS a second apart do not, because the timestamp is in the hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_number_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_number_id')->constrained()->cascadeOnDelete();
            // Denormalized: every read of this table is scoped to a workspace,
            // and the broadcast that follows an insert needs the tenant before
            // it can name a channel.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('provider_message_id')->nullable();
            // `from` is reserved in SQL; the column says who sent it either way.
            $table->string('sender', 120)->nullable();
            $table->text('body')->nullable();
            // The OTP, already extracted upstream. Null when the SMS carried no
            // recognizable code — which is normal, not a failure.
            $table->string('code', 32)->nullable();
            $table->timestamp('received_at')->nullable()->index();

            $table->string('dedupe_key', 64);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['virtual_number_id', 'dedupe_key']);
            $table->index(['tenant_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_number_messages');
    }
};
