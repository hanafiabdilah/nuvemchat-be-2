<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM automations: when something happens to a lead, a deal, a customer or a
 * task (a trigger), and the record matches (conditions), do these things
 * (actions). Every run is kept — what it acted on, what each action did, and
 * why one failed — because an automation nobody can audit is one nobody
 * trusts with their pipeline.
 *
 * `crm_automation_runs.dedupe_key` is what makes the time-based triggers
 * fire once: "deal sat in this stage for 7 days" is claimed per automation,
 * deal and stage entry by a unique row, so the scan that runs every few
 * minutes never repeats it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('trigger', 40);
            $table->json('trigger_config')->nullable();
            $table->string('match', 3)->default('all');
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            // Rotation and other bookkeeping the actions keep between runs.
            $table->json('state')->nullable();
            $table->unsignedInteger('runs_count')->default(0);
            $table->unsignedInteger('failures_count')->default(0);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('paused_reason', 40)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'trigger', 'is_active']);
        });

        Schema::create('crm_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained('crm_automations')->cascadeOnDelete();
            $table->string('trigger', 40);
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_title', 255)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->string('status', 12)->default('queued');
            $table->json('results')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'dedupe_key']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['automation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_automation_runs');
        Schema::dropIfExists('crm_automations');
    }
};
