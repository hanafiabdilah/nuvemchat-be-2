<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's own media library: files uploaded once and sent many times.
 *
 * Deliberately not the same thing as `messages.attachment`. Message media is
 * something that happened — it arrives on its own, belongs to a thread, and is
 * deleted by `media:purge` a month or three later. A gallery asset is something
 * the workspace decided to keep: it has a name, it outlives every message that
 * used it, and the tenant pays for the bytes. Nothing in the retention sweep
 * looks at this table, and nothing here is ever purged on a timer.
 *
 * `uuid` + `public_filename` are what the signed public URL is built from, and
 * both are immutable for the life of the row: the signature covers them, so a
 * rename that touched either would silently break every message already sent
 * with that file. Renaming edits `name` alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Public handle. Not the id: the URL is fetched by Meta, Telegram
            // and anyone the tenant sends the file to, and a sequential id in
            // it would publish how much the platform stores.
            $table->uuid('uuid')->unique();

            // The last path segment of the public URL, so the URL ends in a
            // real filename with a real extension. OutboundMedia derives the
            // MIME type from exactly that, and WhatsApp shows it as the
            // document's name.
            $table->string('public_filename', 180);

            // Who put it there. Nullable and nulled on delete: the file
            // outlives the person who uploaded it, and losing the name is not
            // a reason to lose the file.
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 255);
            $table->string('path', 512);
            $table->string('mime_type', 160);
            $table->string('type', 16)->index();
            $table->unsignedBigInteger('size_bytes');

            // sha256 of the bytes. Unique per tenant, which is what makes the
            // upload path free of a second copy: re-uploading a file the
            // workspace already has returns the row it already paid for
            // instead of charging the quota twice for identical bytes.
            $table->string('checksum', 64);

            // Surfaces "used last week" without joining messages, and gives a
            // safe ordering for the picker.
            $table->timestamp('last_used_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'checksum']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_assets');
    }
};
