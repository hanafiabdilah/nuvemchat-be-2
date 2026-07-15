<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WhatsApp LID alias. WhatsApp can address the same person by either their phone
     * number or a @lid (Linked Identity). The delivery webhook (when we send) carries
     * only the @lid, while incoming messages carry both the phone (sender.id) and the
     * @lid (sender.senderLid). We canonicalise a contact by phone (external_id) and keep
     * the @lid here so a later outgoing send — which knows only the @lid — still resolves
     * to the same contact instead of creating a duplicate.
     *
     * Guarded with hasColumn so it is safe to run on databases where the column already
     * exists (an earlier iteration added it) as well as on fresh installs.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('contacts', 'lid')) {
                $table->string('lid')->nullable()->after('external_id');
                $table->index('lid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'lid')) {
                $table->dropIndex(['lid']);
                $table->dropColumn('lid');
            }
        });
    }
};
