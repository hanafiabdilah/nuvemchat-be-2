<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phones that receive push notifications (Firebase Cloud Messaging) for a user.
 *
 * ⚠️ Tied to the login session (`personal_access_token_id`, cascade), not only
 * to the user. A push carries a customer's name and that a message arrived; a
 * phone that logged out — or whose session was revoked by a password change,
 * or whose account was removed — must stop receiving them the moment the
 * session ends, and the cascade is what makes every one of those paths do it
 * without each having to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()
                ->constrained('personal_access_tokens')->cascadeOnDelete();
            // One row per app installation: a refreshed FCM token replaces the
            // old one instead of piling up beside it.
            $table->string('device_id', 191)->unique();
            $table->string('token', 512)->unique();
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();
            $table->string('locale', 16)->nullable();
            $table->timestamp('last_registered_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->string('last_error', 191)->nullable();
            $table->timestamps();

            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
