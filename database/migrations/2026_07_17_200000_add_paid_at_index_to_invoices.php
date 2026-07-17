<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revenue reporting always filters on paid invoices within a date window
     * (`status = paid AND paid_at BETWEEN ...`). `status` was indexed on its own;
     * `paid_at` was not indexed at all.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['status', 'paid_at'], 'invoices_status_paid_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_status_paid_at_index');
        });
    }
};
