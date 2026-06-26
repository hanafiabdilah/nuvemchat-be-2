<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global platform plans, managed by super-admin. No tenant_id — plans are
     * shared across the whole platform and tenants subscribe to one.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Money stored as integer cents, BRL.
            $table->unsignedInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('BRL');
            $table->string('billing_cycle')->default('monthly'); // monthly | yearly
            $table->unsignedInteger('trial_days')->default(0);

            // Resource quotas + feature flags as JSON (low-cardinality, hot-path cacheable).
            $table->json('quotas')->nullable();   // {"max_connections":3,"max_agents":5,"max_ai_runs":1000}
            $table->json('features')->nullable(); // {"flow":true,"ai_agent_hub":false,"statistics":true}

            $table->boolean('is_active')->default(true);  // hidden from picker when false
            $table->boolean('is_public')->default(true);  // false = comp/hidden-only plan
            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('mp_card_enabled')->default(true);
            $table->boolean('mp_pix_enabled')->default(true);
            $table->string('mp_preapproval_plan_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
