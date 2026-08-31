<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trained agent purchases are charges with no plan subscription behind them —
 * the same shape API Way already introduced — so invoices gain a link to the
 * hire being paid for. `purpose` gains one more value (`trained_agent_purchase`)
 * but the column already exists and is free text at the DB level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('trained_agent_hire_id')->nullable()->after('apiway_subscription_id')
                ->constrained('trained_agent_hires')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trained_agent_hire_id');
        });
    }
};
