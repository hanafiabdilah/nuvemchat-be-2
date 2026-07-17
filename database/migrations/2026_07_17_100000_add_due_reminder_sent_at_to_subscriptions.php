<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marker for billing:send-due-reminders. Unlike the other subscription
     * notifications there is no status change to key off — the subscription just
     * sits Active as its due date approaches — so "already reminded this cycle"
     * needs recording. Compared against current_period_start, so it goes stale by
     * itself when the period rolls over.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('due_reminder_sent_at')->nullable()->after('grace_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('due_reminder_sent_at');
        });
    }
};
