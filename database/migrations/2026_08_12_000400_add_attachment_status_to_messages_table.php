<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Media is downloaded off the webhook now (see DownloadInboundMedia), so a
     * message can exist with its text stored and its bytes still in flight.
     * This column is what lets the dashboard tell that apart from a message
     * that simply has no media, and from one whose download failed for good.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('attachment_status')->nullable()->after('attachment');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('attachment_status');
        });
    }
};
