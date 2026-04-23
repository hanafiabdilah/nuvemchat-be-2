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
        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->onDelete('cascade');
            $table->string('emoji', 10); // ❤, 👍, 😂, etc
            $table->string('sender_type', 20); // 'incoming' atau 'outgoing'
            $table->timestamps();

            // Satu sender hanya bisa punya 1 reaction per message (last reaction wins)
            $table->unique(['message_id', 'sender_type']);
            $table->index('message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
