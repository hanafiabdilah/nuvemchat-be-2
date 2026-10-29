<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folders in the media library.
 *
 * A folder is a label with a place in a tree, and nothing else: no file moves
 * on disk when it is put in one, and no URL changes. That matters because every
 * gallery URL is signed and already handed to WhatsApp, Meta's fetchers and
 * every message sent with the file — a folder that rewrote paths would break
 * all of them to tidy a screen.
 *
 * Both foreign keys null on delete, and that is a safety net rather than the
 * behaviour: deleting a folder through the product moves what it held to its
 * parent first (GalleryFolderService::delete). A cascade that bypassed that
 * would drop the files at the top level, which is still better than deleting
 * them — a file nobody chose to delete must never go with its folder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('gallery_folders')->nullOnDelete();
            $table->string('name', 120);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'parent_id']);
        });

        Schema::table('gallery_assets', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('origin')
                ->constrained('gallery_folders')->nullOnDelete();

            $table->index(['tenant_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::table('gallery_assets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'folder_id']);
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('gallery_folders');
    }
};
