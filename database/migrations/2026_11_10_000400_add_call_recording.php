<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recording WhatsApp calls answered in the dashboard.
 *
 * Per connection and off by default: recording a customer is a decision the
 * business takes (and tells the customer about — `call_recording_notice` is
 * sent in the chat as the call connects), never one a deploy takes for it.
 *
 * The recording itself is captured by the answering browser (it is the only
 * place the audio exists) and uploaded in pieces while the call runs; the
 * finished file is attached to the call's note in the thread, which is what
 * puts it under the same retention as every other media file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->boolean('call_recording')->default(false)->after('call_busy_message');
            $table->text('call_recording_notice')->nullable()->after('call_recording');
        });

        Schema::table('calls', function (Blueprint $table) {
            // recording → processing → transcribing → ready | empty | failed
            $table->string('recording_status', 16)->nullable()->after('duration_seconds');
            $table->string('recording_mime', 64)->nullable()->after('recording_status');
            $table->unsignedInteger('recording_parts')->default(0)->after('recording_mime');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn(['recording_status', 'recording_mime', 'recording_parts']);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['call_recording', 'call_recording_notice']);
        });
    }
};
