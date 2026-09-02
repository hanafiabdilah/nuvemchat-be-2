<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The published price of each model a workspace can rent.
 *
 * Two jobs, and only one of them moves money.
 *
 * The one that does: `markup_pct`, a per-model override of the platform-wide
 * margin. Models are not equally worth reselling — a cheap one carries almost
 * no absolute margin at the default percentage, an expensive one carries more
 * risk per run — and without this the only lever is a single number applied to
 * everything.
 *
 * The one that does not: `input_usd_per_1m` / `output_usd_per_1m`, the
 * provider's own list price, kept so a workspace can be *shown* what a model
 * costs before choosing it. Deliberately **not** the billing basis: the actual
 * charge is still the cost the hub reports for the run that really happened. A
 * table of list prices drifts the moment a provider changes theirs, and a bill
 * computed from stale numbers is a bill that is quietly wrong in one direction
 * or the other. Here it is an estimate on a page; there it would be the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('model');
            /** Customer-facing name. Null = show the model id, which is usually fine. */
            $table->string('label')->nullable();

            // USD per 1M tokens, as the provider publishes it. Nullable because
            // a model can be listed for its markup alone before anyone has
            // looked up the price.
            $table->decimal('input_usd_per_1m', 12, 4)->nullable();
            $table->decimal('output_usd_per_1m', 12, 4)->nullable();

            // Null = use the platform-wide markup.
            $table->decimal('markup_pct', 6, 2)->nullable();

            // Whether it appears on the customer's price list. A model can be
            // priced (and billed at its own markup) without being advertised —
            // legacy models workspaces still run on, for instance.
            $table->boolean('is_listed')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // One row per model per provider: the pair is the identity, and a
            // second row would make "which markup applies?" unanswerable.
            $table->unique(['provider', 'model']);
            $table->index(['is_listed', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_prices');
    }
};
