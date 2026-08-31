<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segments the trained-agent catalog is grouped by (accounting, medical
 * office, gym, …). Platform-owned and edited in the Back Office — the whole
 * point is that a new vertical can be added without a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trained_agent_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('description', 500)->nullable();
            // Lucide icon name, rendered by both SPAs. Free text on purpose:
            // an unknown name falls back to a default rather than failing.
            $table->string('icon', 60)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trained_agent_categories');
    }
};
