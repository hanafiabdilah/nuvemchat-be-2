<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM, phase 2: leads, deals and their pipelines, tasks, and logged activity.
 *
 *  - `crm_leads`: someone who might buy, not yet a deal — always about a
 *    customer (the person), with a status from new to converted, a score,
 *    and who is working it.
 *  - `crm_pipelines` / `crm_pipeline_stages`: the board a deal moves along.
 *    A stage's `kind` (open | won | lost) is what decides a deal's outcome;
 *    its `probability` is what the weighted totals use.
 *  - `crm_deals`: a sale being worked, in one stage of one pipeline, with a
 *    value in minor units of the workspace currency (the market's, like
 *    every other amount here).
 *  - `crm_tasks`: a follow-up with a due time and an optional reminder, about
 *    a customer, lead, deal or company.
 *  - `crm_activities` gains `customer_id` — every entry about a lead or deal
 *    also belongs to the person, so their timeline is one query — and `body`
 *    for what people log themselves (notes, calls, meetings, e-mails).
 *
 * Every row about a person carries `customer_id`; merging two customers moves
 * them all (CustomerService::absorb).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'position']);
        });

        Schema::create('crm_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->string('kind', 10)->default('open');
            $table->string('color', 20)->nullable();
            $table->timestamps();

            $table->index(['pipeline_id', 'position']);
        });

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('new');
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('source', 40)->nullable();
            $table->unsignedBigInteger('value_cents')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disqualified_reason', 255)->nullable();
            $table->unsignedBigInteger('converted_deal_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'owner_user_id']);
        });

        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('crm_pipeline_stages')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('value_cents')->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->date('expected_close_date')->nullable();
            $table->string('status', 10)->default('open');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('stage_entered_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['pipeline_id', 'stage_id', 'position']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'owner_user_id']);
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'completed_at', 'due_at']);
            $table->index(['assignee_user_id', 'completed_at']);
            // The reminder sweep's only query.
            $table->index(['remind_at', 'reminded_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('crm_activities', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('subject_id');
            $table->text('body')->nullable()->after('type');
            $table->index(['customer_id', 'occurred_at']);
        });

        // Entries written in phase 1 were all about a customer or a company.
        DB::table('crm_activities')->where('subject_type', 'customer')->update(['customer_id' => DB::raw('subject_id')]);
    }

    public function down(): void
    {
        Schema::table('crm_activities', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'occurred_at']);
            $table->dropColumn(['customer_id', 'body']);
        });

        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_pipeline_stages');
        Schema::dropIfExists('crm_pipelines');
    }
};
