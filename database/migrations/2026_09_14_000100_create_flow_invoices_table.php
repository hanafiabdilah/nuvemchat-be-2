<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every nota fiscal a flow's invoice node asked the workspace's own issuing
 * platform (Spedy, Notasy) to emit.
 *
 * The same shape as flow_payments, for the same reason: issuing is asynchronous.
 * The provider accepts the request, sends it to the prefeitura or to SEFAZ, and
 * the authorization (or the rejection) comes back later — through a webhook, the
 * polling sweep, or the deadline check. All three converge on this row, and
 * `status` only ever leaves `processing` once, under a row lock, which is what
 * resumes the flow exactly once.
 *
 * `reference` is ours and is what we send as the provider's integration id /
 * external reference: a retried create finds the invoice it already made rather
 * than emitting a second fiscal document — which, unlike a duplicate Pix nobody
 * pays, is a real tax record somebody has to cancel by hand.
 *
 * The customer's CPF/CNPJ is kept because it is printed on the document the
 * customer receives; the invoice list shows it masked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Nulled rather than cascaded: deleting an integration must not
            // erase the record that a fiscal document was issued through it.
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40)->nullable();

            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_state_id')->nullable()->constrained('flow_states')->nullOnDelete();
            $table->foreignId('flow_node_id')->nullable()->constrained('flow_nodes')->nullOnDelete();
            // The charge this invoice is for, when the node ran after a
            // payment node — so the list can say "for the Pix of R$ 49,90".
            $table->foreignId('flow_payment_id')->nullable()->constrained('flow_payments')->nullOnDelete();

            $table->string('reference', 64)->unique();
            $table->string('provider_invoice_id', 128)->nullable()->index();

            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('description', 2000)->nullable();

            $table->string('customer_name', 255)->nullable();
            $table->string('customer_document', 20)->nullable();
            $table->string('customer_email', 255)->nullable();

            $table->string('status', 20);

            // What the authority returned, once it did.
            $table->string('number', 64)->nullable();
            $table->text('pdf_url')->nullable();
            $table->text('xml_url')->nullable();

            // How long the flow waits for the authorization before taking the
            // failed branch. The invoice itself may still be authorized after.
            $table->timestamp('wait_until')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'wait_until']);
            $table->index(['integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_invoices');
    }
};
