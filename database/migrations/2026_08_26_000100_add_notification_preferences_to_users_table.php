<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user notification settings — which incoming messages are allowed to
     * make noise in this person's dashboard.
     *
     * Kept apart from `ui_preferences` on purpose: that column is cosmetic
     * (theme, appearance) and a wrong value there costs a repaint, while this
     * one decides whether an agent hears a customer at all. A single JSON blob
     * for the same reason as the other: never queried, and new switches must
     * not cost a migration.
     *
     * Null = never touched it = notify about everything, which is what the app
     * did before this column existed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('ui_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
