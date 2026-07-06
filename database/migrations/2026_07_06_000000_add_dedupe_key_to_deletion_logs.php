<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an idempotency key to the Meta data-deletion audit logs so retried
 * callbacks (same user_id + issued_at) are recognized and not reprocessed.
 * Nullable because legacy rows and payloads without `issued_at` have no key;
 * unique index tolerates multiple NULLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_deletion_logs', function (Blueprint $table) {
            $table->string('dedupe_key')->nullable()->unique()->after('instagram_user_id');
        });

        Schema::table('facebook_deletion_logs', function (Blueprint $table) {
            $table->string('dedupe_key')->nullable()->unique()->after('facebook_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_deletion_logs', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });

        Schema::table('facebook_deletion_logs', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
