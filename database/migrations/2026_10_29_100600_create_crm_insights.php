<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM, phase 3: segments, saved views and lead scoring.
 *
 *  - `crm_segments`: a named group of customers defined by rules (status,
 *    tags, channel, owner, last contact, open deals…), shared across the
 *    workspace and evaluated live — membership is never copied, so a
 *    customer is in a segment exactly while the rules say so, and its count
 *    is always what the reader may see.
 *  - `crm_saved_views`: a list page's filters under a name — one person's,
 *    or shared with the workspace.
 *  - `tenants.crm_lead_scoring`: the workspace's scoring rules; with them on,
 *    a lead's score is computed (`crm_leads.score_details` says why).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('match', 3)->default('all');
            $table->json('rules');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('crm_saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity', 20);
            $table->string('name', 100);
            $table->json('filters');
            $table->boolean('shared')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'entity']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->json('crm_lead_scoring')->nullable();
        });

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->json('score_details')->nullable()->after('score');
            $table->timestamp('score_updated_at')->nullable()->after('score_details');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn(['score_details', 'score_updated_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('crm_lead_scoring');
        });

        Schema::dropIfExists('crm_saved_views');
        Schema::dropIfExists('crm_segments');
    }
};
