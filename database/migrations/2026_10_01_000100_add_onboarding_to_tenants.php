<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first-run guide a new workspace passes before the dashboard.
 *
 * Only the one decision nothing else records is stored here: that the owner
 * chose to skip it. Paying for a plan and renting a number already leave their
 * own rows (a paid invoice, a virtual number), and OnboardingState reads those
 * — a second copy of the same fact is how the two drift apart.
 *
 * No backfill, on purpose: a workspace that has neither paid nor bought
 * anything is exactly who the guide is for, however old it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('onboarding_skipped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('onboarding_skipped_at');
        });
    }
};
