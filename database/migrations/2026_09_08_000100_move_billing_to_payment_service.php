<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing stops naming a gateway.
     *
     * Pingly used to hold MercadoPago identifiers directly — `mp_payment_id`,
     * `mp_preapproval_id`, `mp_card_enabled`. It now talks to the group's own
     * payment service, which owns every gateway account and decides which one
     * takes a charge, so a column named after one of them is a column that will
     * be wrong the first time that decision changes.
     *
     * The values are kept. A `payment_id` holding a MercadoPago id is a correct
     * archival reference to a charge that really did run there; blanking it
     * would delete the only link between a paid invoice and the money.
     *
     * Two columns do go, because nothing can read them any more: a preapproval
     * is a MercadoPago-only object and there is no equivalent to migrate it to.
     * Recurring is now a stored instrument plus a scheduler of ours, and that
     * lives in the new `payment_instrument_id`.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('mp_payment_id', 'payment_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('mp_preapproval_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            /*
              The idempotency anchor, and the reason a cycle cannot be billed
              twice: the pair of product and order reference is unique in the
              payment service's own database, so a double-firing scheduler, two
              racing workers and an operator pressing retry all converge on one
              payment. Stored here so an inbound webhook can match back even
              before we learned the payment id.
            */
            $table->string('order_reference')->nullable()->unique()->after('payment_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // ⚠️ The index first, and in its own statement. SQLite refuses to
            // drop a column an index still names, and it reports it as a
            // corrupt-index error rather than as the ordering problem it is.
            $table->dropIndex(['mp_preapproval_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('mp_preapproval_id');

            // The stored card (or Pix consent) a renewal is charged against.
            // Cleared the moment the service tells us it stopped working, so
            // billing:charge-renewals never keeps hammering a dead card.
            $table->string('payment_instrument_id')->nullable()->after('payment_method');
            $table->string('payment_customer_id')->nullable()->after('payment_instrument_id');
        });

        Schema::table('apiway_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['mp_preapproval_id']);
        });

        Schema::table('apiway_subscriptions', function (Blueprint $table) {
            $table->dropColumn('mp_preapproval_id');
        });

        /*
          Who is being billed, in the acquirer's terms.

          New, and not optional: a Pix or a boleto without a CPF or CNPJ is
          refused by the acquirer, so the payment service demands one on every
          charge. MercadoPago let us get away with an e-mail alone.

          On the tenant rather than the user, for two reasons. The billing
          identity belongs to the workspace being charged, not to whichever
          agent happened to press Pay — a company does not stop being a CNPJ
          when a different person renews it. And a renewal runs from a
          scheduler with nobody at a screen at all, so it has to be a stored
          fact rather than something a form supplies.
        */
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('billing_name')->nullable();
            $table->string('billing_document_type', 8)->nullable(); // CPF | CNPJ
            $table->string('billing_document_number', 32)->nullable();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->renameColumn('mp_card_enabled', 'card_enabled');
            $table->renameColumn('mp_pix_enabled', 'pix_enabled');
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('provider')->default('payment_service')->change();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('provider')->default('mercadopago')->change();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->renameColumn('card_enabled', 'mp_card_enabled');
            $table->renameColumn('pix_enabled', 'mp_pix_enabled');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['billing_name', 'billing_document_type', 'billing_document_number']);
        });

        Schema::table('apiway_subscriptions', function (Blueprint $table) {
            $table->string('mp_preapproval_id')->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['payment_instrument_id', 'payment_customer_id']);
            $table->string('mp_preapproval_id')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['order_reference']);
            $table->dropColumn('order_reference');
            $table->string('mp_preapproval_id')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('payment_id', 'mp_payment_id');
        });
    }
};
