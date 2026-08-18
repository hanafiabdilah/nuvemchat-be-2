<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "This background process was alive at this moment."
     *
     * Half of what keeps this platform running never touches an HTTP request:
     * the Discord gateway daemon, the queue workers, the scheduler's renewals
     * and purges. When one of them dies the symptom shows up days later as a
     * customer ticket — nothing in the product notices on its own.
     *
     * A row per process, rewritten in place each time it checks in. Deliberately
     * a table and not the cache: the cache is flushable and a flush would read
     * as "every daemon just died". Deliberately not a log either — the only
     * question anyone asks is "when did it last run", so history is noise.
     */
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamp('beat_at');
            // Whatever the process wants to say about itself: sessions held,
            // rows processed, the run's outcome.
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
