<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WhatsApp number captured at registration + when it was verified via OTP.
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'whatsapp_number')) {
                $table->string('whatsapp_number')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'whatsapp_verified_at')) {
                $table->timestamp('whatsapp_verified_at')->nullable()->after('whatsapp_number');
            }
        });

        // One-time passwords for verifying a WhatsApp number (and future purposes).
        Schema::create('otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('whatsapp_number');
            $table->string('code', 10);
            $table->string('purpose')->default('whatsapp_verification');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_number', 'purpose']);
        });

        // Audit trail of every WhatsApp message the platform sends (OTP + notifications).
        Schema::create('whatsapp_message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');            // pingly | wapi | proxybr
            $table->string('recipient');           // destination phone
            $table->string('type');                // otp | notification:<event>
            $table->text('body')->nullable();
            $table->string('status');              // sent | failed
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
        Schema::dropIfExists('otps');
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'whatsapp_verified_at')) $table->dropColumn('whatsapp_verified_at');
            if (Schema::hasColumn('users', 'whatsapp_number')) $table->dropColumn('whatsapp_number');
        });
    }
};
