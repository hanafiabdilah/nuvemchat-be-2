<?php

use App\Enums\Broadcast\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A broadcast campaign: one message, one connection, many recipients.
     *
     * The four counters are denormalised on purpose. The progress bar is polled
     * and broadcast several times a second while a campaign runs, and counting
     * 5.000 recipient rows per frame to render it would cost far more than the
     * writes that keep these in step.
     *
     * `last_tick_at` exists for the watchdog (broadcasts:tick): a `running`
     * campaign whose pump job was lost with the queue worker has no other way
     * of announcing that nobody is driving it any more.
     */
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            // Who to blame, and who the conversations it opens are assigned to.
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // Tag stamped on every conversation the campaign touches, so an
            // agent can filter (or clean up) a blast afterwards. Created lazily
            // on start, hence nullable.
            $table->foreignId('tag_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('status')->default(Status::Draft->value);
            $table->string('content_type');
            $table->json('payload');

            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedInteger('rate_per_minute');

            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_tick_at')->nullable();
            // Why the campaign as a whole gave up (not why one recipient did).
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            // Drives the watchdog's two sweeps: due schedules, and running
            // campaigns nobody has ticked lately.
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
