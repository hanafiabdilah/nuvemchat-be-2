<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery storage rented on top of whatever the plan includes.
 *
 * One row per tenant — a rental is an amount, not a collection of purchases, so
 * there is nothing to list and everything to add up wrongly if there were
 * several. Changing the amount edits this row.
 *
 * `gb` is what the tenant has right now and `pending_gb` is what they asked to
 * drop to at the next renewal. Reductions cannot take effect immediately
 * because the month is already paid: applying one on the spot would take back
 * storage the customer owns, and refunding it would be the first refund on a
 * balance that never refunds anything else. Increases have no such problem and
 * are charged pro rata on the spot.
 *
 * `price_per_gb_cents` is copied onto the row at every charge rather than read
 * from settings when it is needed: the platform price moves, and a renewal that
 * silently reprices itself is one the customer cannot check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_storage_rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->unique();

            $table->unsignedInteger('gb')->default(0);
            // Null = no scheduled change. 0 = "cancel at the renewal", which is
            // how a tenant stops paying without losing the month they bought.
            $table->unsignedInteger('pending_gb')->nullable();

            $table->unsignedInteger('price_per_gb_cents')->default(0);
            $table->string('currency', 3)->default('BRL');

            $table->string('status', 20)->index();

            $table->timestamp('started_at')->nullable();
            // The day the next month is charged, and the deadline after which
            // an unpayable rental is cancelled. Cancelling never deletes a
            // file — it only takes the extra allowance away, which leaves the
            // gallery read-only until the tenant pays or frees space.
            $table->timestamp('renews_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('renewal_reminder_sent_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_storage_rentals');
    }
};
