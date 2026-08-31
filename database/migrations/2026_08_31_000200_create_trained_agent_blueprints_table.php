<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pre-trained agent the platform sells: the agent's core settings plus the
 * whole training payload that gets copied into the tenant's workspace on hire.
 *
 * Training lives in JSON columns rather than child tables because a blueprint
 * is a template, never a live entity — nothing runs against it, nothing joins
 * to its knowledge rows, and the Back Office edits the lot in one form. The
 * real, queryable rows are the ones created on the hub at hire time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trained_agent_blueprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trained_agent_category_id')->nullable()
                ->constrained('trained_agent_categories')->nullOnDelete();

            $table->string('name', 150);
            $table->string('slug', 170)->unique();
            $table->string('tagline', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('icon', 60)->nullable();

            // Agent core — the same fields AiHubAgent carries.
            $table->string('model', 100);
            $table->text('system_prompt');
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->json('handoff_rules')->nullable();

            // Training payload, copied verbatim on hire.
            $table->json('profile')->nullable();            // language, tone, responseStyle, instructions[], limits{}
            $table->json('knowledge')->nullable();          // [{title, content, tags[]}]
            $table->json('skills')->nullable();             // [{name, description, instructions[]}]
            $table->json('training_examples')->nullable();  // [{type, input, expected_output, notes}]

            // Commerce. Price is what a tenant pays ONCE when the plan's
            // included slots are gone; zero means the blueprint is only ever
            // available through the plan allowance.
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('BRL');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'is_public']);
            // Named explicitly: the generated name would be 67 characters and
            // MySQL caps identifiers at 64 (SQLite does not, so the test suite
            // would never have caught it).
            $table->index(['trained_agent_category_id', 'sort_order'], 'ta_blueprints_category_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trained_agent_blueprints');
    }
};
