<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('instagram_deletion_logs', function (Blueprint $table) {
            $table->id();
            $table->string('confirmation_code')->unique();
            $table->string('instagram_user_id');
            $table->string('status')->default('completed'); // pending, processing, completed
            $table->integer('connections_deleted')->default(0);
            $table->integer('conversations_deleted')->default(0);
            $table->integer('messages_deleted')->default(0);
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable(); // Additional metadata
            $table->timestamps();

            $table->index('confirmation_code');
            $table->index('instagram_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instagram_deletion_logs');
    }
};
