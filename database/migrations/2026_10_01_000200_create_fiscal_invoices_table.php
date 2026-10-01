<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas fiscais (NFS-e) that Pingly issues to its own Brazilian customers for
 * the invoices they pay, through Plugnotas.
 *
 * One row per paid invoice — `invoice_id` is unique, which is what makes the
 * observer, the job, the sweep and a retried webhook converge on one nota
 * instead of racing to issue two. `attempt` is part of the reference sent to
 * Plugnotas (`idIntegracao`): a rejected nota keeps its number in their system,
 * so the corrected retry needs a new one.
 *
 * `tenants.billing_address` rides along: the prefeitura asks for the tomador's
 * address in many cities, and a CPF/CNPJ is all the billing profile held.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 32)->default('plugnotas');
            $table->string('status', 24)->index();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('reference', 64)->unique();
            // Plugnotas' own id (24 hex) and the protocol of the submission.
            $table->string('provider_id', 64)->nullable()->index();
            $table->string('protocol', 64)->nullable();
            // What the prefeitura gave back once authorized.
            $table->string('number', 32)->nullable();
            $table->string('verification_code', 64)->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->string('description', 2000)->nullable();
            // The last thing Plugnotas or the prefeitura said, verbatim. Shown
            // to operators only — a tenant reads a status, never this.
            $table->text('message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->json('billing_address')->nullable()->after('billing_document_number');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('billing_address');
        });

        Schema::dropIfExists('fiscal_invoices');
    }
};
