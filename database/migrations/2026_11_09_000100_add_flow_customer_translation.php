<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A flow that answers each customer in the customer's own language.
     *
     * Three pieces, each read by a different moment:
     *
     * - `flows.translate_to_customer` + `flows.language`: the switch, and the
     *   language the author wrote the flow in. Null language = the workspace's
     *   market language, which is what almost every flow is written in.
     *
     * - `contacts.language` + `language_source`: what the customer speaks.
     *   Detected once from what they wrote (`detected`) or set by an agent
     *   (`manual`, which detection never overwrites). On the contact, not the
     *   conversation: the person writing again next month speaks the same
     *   language.
     *
     * - `flow_text_translations`: every translation ever made, keyed by the
     *   source text and not by node or flow. A node's id changes when a flow is
     *   saved from an editor that was already open, and the same "Obrigado!"
     *   appears in ten flows — keyed by text, both are one lookup. Editing a
     *   text simply stops matching the old row.
     */
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->boolean('translate_to_customer')->default(false)->after('name');
            $table->string('language', 16)->nullable()->after('translate_to_customer');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('language', 16)->nullable()->after('username');
            $table->string('language_source', 16)->nullable()->after('language');
        });

        Schema::create('flow_text_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('language', 16);
            $table->char('source_hash', 64);
            $table->text('text');
            $table->timestamps();

            $table->unique(['tenant_id', 'language', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_text_translations');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['language', 'language_source']);
        });

        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn(['translate_to_customer', 'language']);
        });
    }
};
