<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group conversations (channel-agnostic):
     * - conversations.type: 'private' (default) or 'group'. A group conversation
     *   keeps contact_id pointing at the contact row that represents the group
     *   itself (contacts.is_group), while each incoming message records its real
     *   sender in messages.contact_id.
     * - conversation_participants: every contact that has spoken in the
     *   conversation, so a group is never tied to a single contact.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('type')->default('private')->after('external_id')->index();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_group')->default(false)->after('name_locked');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('conversation_id')->constrained()->nullOnDelete();
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('is_group');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
