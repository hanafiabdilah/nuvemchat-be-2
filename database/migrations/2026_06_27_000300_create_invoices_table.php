<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unified billing record for both card and pix charges. A pix charge is
     * just an invoice with payment_method=pix plus the pix_* columns.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            $table->string('status')->index(); // pending|paid|failed|expired|refunded|cancelled
            $table->string('payment_method'); // card|pix|manual

            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('BRL');

            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->timestamp('paid_at')->nullable();

            // MercadoPago references — unique mp_payment_id gives webhook idempotency.
            $table->string('mp_payment_id')->nullable()->unique();
            $table->string('mp_preapproval_id')->nullable();

            // Pix charge data (point_of_interaction.transaction_data).
            $table->text('pix_qr_code')->nullable();
            $table->longText('pix_qr_code_base64')->nullable();
            $table->text('pix_copy_paste')->nullable();
            $table->timestamp('pix_expires_at')->nullable();

            $table->string('idempotency_key')->nullable()->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
