<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A campaign and an Instagram post outlive the person who wrote them.
 *
 * Both `created_by` columns were NOT NULL with ON DELETE CASCADE, so removing
 * an agent deleted every campaign they had ever run (recipients and all) and
 * every post they had scheduled — the scheduled ones then simply never went
 * out. App\Services\User\AgentRemoval now clears the column before the user
 * row goes, which needs the column to accept null. The cascade itself is left
 * alone: by the time it could fire, nothing points at the removed person.
 *
 * Both tables hold one row per campaign / per post — small — so the column
 * change is cheap; nothing here touches messages or conversations.
 */
return new class extends Migration
{
    private const TABLES = ['broadcasts', 'instagram_posts'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            // A row whose author was removed has nobody to point back at, and
            // inventing one would be worse than leaving the column nullable.
            if (DB::table($name)->whereNull('created_by')->exists()) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable(false)->change();
            });
        }
    }
};
