<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The flow assistant's transcript, kept per flow rather than per person.
 *
 * Until now the conversation lived in the browser and was posted back with
 * every turn, which made it private to one tab: closing the panel lost it, and
 * a second agent opening the same flow saw an empty box with no idea what had
 * already been asked or why the flow looks the way it does. A flow is a shared
 * artefact — whoever can edit it can edit what the assistant built — so the
 * reasoning behind it belongs to the flow too.
 *
 * `user_id` is who spoke, not who may read: every agent with access to the flow
 * reads the whole thread. It is nullable because the row outlives the account
 * (an agent who leaves does not erase the decisions they made here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_assistant_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            // Denormalised from the flow so every read is scoped without a
            // join, the same reason `ai_hub_runs` carries one.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('role', 16); // user | assistant
            $table->text('content');

            /**
             * The blueprint this turn produced, if any.
             *
             * Kept so a proposal stays re-appliable after a reload — somebody
             * who reads "montei 6 etapas" and wants it on the canvas should not
             * have to ask again and pay for a second run. Null on a plain
             * answer, and on the user's own turns.
             */
            $table->json('blueprint')->nullable();
            $table->json('warnings')->nullable();

            $table->timestamps();

            // The only read there is: one flow's thread, oldest first.
            $table->index(['flow_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_assistant_messages');
    }
};
