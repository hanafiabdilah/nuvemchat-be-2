<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reservations: what a customer can book, and the bookings themselves.
 *
 *  - `reservable_services` is what is booked — a table, a haircut, a court —
 *    with its opening hours, how long one booking lasts, how often one may
 *    start, how many fit at once (per booking or per person), the customer's
 *    booking rules, whether a booking is confirmed by itself, and whether it
 *    asks for money first. Hours are JSON in the same shape as
 *    `connections.service_hours.days` (`{"mon": [{"open": "09:00", "close":
 *    "17:00"}], ...}`) so the dashboard reuses one weekly editor; special dates
 *    (`date_overrides`) replace a day's hours — an empty list closes it,
 *    `full: true` takes no more bookings that day.
 *  - `reservations` holds every booking, whoever wrote it: the AI in a
 *    conversation, or the team on the calendar for one that came by phone.
 *    One table on purpose — a slot taken at the desk has to be taken for the
 *    AI too, and two tables would be two answers to "is 19:00 free?". That is
 *    also what makes confirming by itself safe: the calendar counts every
 *    booking the business has, not only the ones the chat saw.
 *
 * Times are stored in UTC like every other column; the service's hours are
 * read in the workspace's own zone (Tenant::displayTimezone()).
 *
 * The service's name and the amount it asks for are copied onto the
 * reservation when it is made: editing or deleting a service later must never
 * rewrite a booking somebody already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservable_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->unsignedSmallInteger('duration_minutes')->default(60);
            // Null = one start per duration (09:00, 10:00 … for an hour).
            $table->unsignedSmallInteger('slot_interval_minutes')->nullable();
            $table->unsignedInteger('capacity')->default(1);
            $table->string('capacity_unit', 20)->default('bookings');
            $table->unsignedInteger('max_party_size')->nullable();

            $table->json('weekly_hours')->nullable();
            $table->json('date_overrides')->nullable();

            $table->unsignedInteger('min_notice_minutes')->default(60);
            $table->unsignedSmallInteger('max_days_ahead')->default(30);
            // On by default: the calendar counts every booking, so a time it
            // shows free is free.
            $table->boolean('auto_confirm')->default(true);
            // Null = customers cannot move or call it off in the chat; the team can.
            $table->unsignedSmallInteger('cancel_until_hours')->nullable()->default(24);

            $table->string('payment_mode', 20)->default('none');
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->unsignedBigInteger('deposit_cents')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'active', 'position']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservable_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_name', 120);
            // What the customer quotes: short, unambiguous letters and digits.
            $table->string('code', 12);
            $table->string('status', 20);
            $table->string('source', 20);

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            // Another time the team offered instead of the one asked for.
            $table->timestamp('offered_starts_at')->nullable();
            $table->unsignedInteger('party_size')->default(1);

            $table->string('customer_name', 160)->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 190)->nullable();
            $table->text('notes')->nullable();

            // Why a request waits for the team instead of being confirmed.
            $table->string('review_reason', 30)->nullable();
            $table->string('review_note', 255)->nullable();

            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_node_id')->nullable()->constrained('flow_nodes')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            // Whether the customer has been told what the team decided.
            $table->string('notify_status', 20)->nullable();
            $table->string('notify_reason', 30)->nullable();
            $table->timestamp('reminded_at')->nullable();

            $table->unsignedBigInteger('amount_due_cents')->default(0);
            $table->string('currency', 3);
            $table->timestamp('paid_at')->nullable();
            // How long an unpaid booking keeps its slot: the payment's own
            // deadline. Past it the slot is free even before the charge is
            // settled as expired.
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            // "What overlaps 19:00–21:00 at this service" — every availability read.
            $table->index(['reservable_service_id', 'starts_at']);
            $table->index(['tenant_id', 'starts_at']);
            $table->index(['contact_id', 'starts_at']);
        });

        Schema::table('flow_payments', function (Blueprint $table) {
            // Set for a charge the AI issued for a reservation; what settle()
            // reads to confirm the booking or free its slot.
            $table->foreignId('reservation_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('flow_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_id');
        });

        Schema::dropIfExists('reservations');
        Schema::dropIfExists('reservable_services');
    }
};
