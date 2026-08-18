<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Byte size of the stored attachment.
     *
     * Media is the one cost that only ever goes up, and `media:purge` turned it
     * into a number somebody has to manage — but nothing anywhere records how
     * big any of it is. Answering "how much disk is this customer using" from
     * the files themselves means a stat() per row across every message ever
     * sent, which is not a query a dashboard can run.
     *
     * Written by MessageAttachmentObserver at the moment `attachment` changes,
     * so all ~20 channel handlers are covered without touching any of them.
     * Null means "not measured": either the file predates this column (until
     * `media:backfill-sizes` reaches it) or there is no attachment at all.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_status');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('attachment_size');
        });
    }
};
