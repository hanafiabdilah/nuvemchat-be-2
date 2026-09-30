<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cards a workspace kept at the gateway (Mercado Pago customers & cards).
 *
 * The card itself lives at the gateway — this is a pointer plus what is safe to
 * show (brand, last four, expiry). No number and no security code ever reach
 * this table: Mercado Pago does not keep the CVV either, which is why every
 * charge on a saved card asks for it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 32);
            // The gateway customer the card belongs to, and the email it was
            // created under — a later charge on this card has to name the same
            // payer.
            $table->string('customer_id', 64);
            $table->string('customer_email')->nullable();
            $table->string('card_id', 64);
            // Mercado Pago's payment_method_id (visa, master, elo…), which a
            // one-off charge must repeat.
            $table->string('brand', 32)->nullable();
            $table->string('payment_type', 32)->nullable();
            $table->string('issuer_id', 32)->nullable();
            $table->string('first_six', 8)->nullable();
            $table->string('last_four', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('holder_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Null until a charge on it succeeded: a card that has never paid
            // for anything is the one a failed first charge takes back out.
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'card_id']);
            $table->index(['tenant_id', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_cards');
    }
};
