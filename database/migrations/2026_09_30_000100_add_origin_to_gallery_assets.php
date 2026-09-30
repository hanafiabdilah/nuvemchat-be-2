<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the gallery list files it does not own.
 *
 * Every existing row is a file uploaded into the library by hand, so the
 * default `gallery` is the truth for all of them and nothing needs backfilling
 * here — the rows that point at flow, campaign and catalog uploads are added by
 * `gallery:index-uploads`, and agent attachments as they are sent.
 *
 * The unique checksum per tenant goes: it said "these bytes are stored once",
 * which is still enforced for library files (GalleryService looks before it
 * writes), but a linked row and a library row of the same bytes are two
 * different facts. What must never repeat is a row per **path** — that is what
 * lets registering an upload twice (a retry, a `replace`) update the row it
 * already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery_assets', function (Blueprint $table) {
            $table->string('origin', 16)->default('gallery')->after('tenant_id');

            // Only for `message` rows: the purge sweep deletes the file, and the
            // row goes with the message it was read off.
            $table->foreignId('message_id')->nullable()->after('origin')
                ->constrained('messages')->cascadeOnDelete();
        });

        // New indexes first: on MySQL the tenant_id foreign key needs an
        // index that starts with tenant_id at every moment, so the old unique
        // is only dropped once its replacements exist.
        Schema::table('gallery_assets', function (Blueprint $table) {
            $table->index(['tenant_id', 'checksum']);
            $table->index(['tenant_id', 'origin']);
            $table->unique(['tenant_id', 'path']);
        });

        Schema::table('gallery_assets', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'checksum']);
        });
    }

    public function down(): void
    {
        DB::table('gallery_assets')->where('origin', '!=', 'gallery')->delete();

        Schema::table('gallery_assets', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'path']);
            $table->dropIndex(['tenant_id', 'origin']);
            $table->dropIndex(['tenant_id', 'checksum']);
            $table->unique(['tenant_id', 'checksum']);
        });

        Schema::table('gallery_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('message_id');
            $table->dropColumn('origin');
        });
    }
};
