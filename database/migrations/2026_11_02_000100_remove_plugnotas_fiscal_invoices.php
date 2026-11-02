<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the notas fiscais Pingly issued for its own invoices through
 * Plugnotas (Nov 2026): the table, the tomador address it added to tenants,
 * the platform settings, and the sync's heartbeat row so Health stops waiting
 * for a process that no longer exists.
 *
 * Production never issued one — no nota, no address, no Plugnotas settings —
 * so nothing a customer entered or received is lost.
 *
 * Not the flow node's notas (`flow_invoices`, Spedy): those belong to the
 * workspace's own integration and stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fiscal_invoices');

        if (Schema::hasColumn('tenants', 'billing_address')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('billing_address');
            });
        }

        DB::table('settings')->where('key', 'like', 'plugnotas.%')->delete();
        DB::table('system_heartbeats')->where('name', 'fiscal-invoices:sync')->delete();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tenants', 'billing_address')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->json('billing_address')->nullable()->after('billing_document_number');
            });
        }

        if (! Schema::hasTable('fiscal_invoices')) {
            Schema::create('fiscal_invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('provider', 32)->default('plugnotas');
                $table->string('status', 24)->index();
                $table->unsignedSmallInteger('attempt')->default(1);
                $table->string('reference', 64)->unique();
                $table->string('provider_id', 64)->nullable()->index();
                $table->string('protocol', 64)->nullable();
                $table->string('number', 32)->nullable();
                $table->string('verification_code', 64)->nullable();
                $table->unsignedBigInteger('amount_cents');
                $table->string('description', 2000)->nullable();
                $table->text('message')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }
};
