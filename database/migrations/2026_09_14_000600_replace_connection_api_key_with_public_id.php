<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Connections get a public id, and lose their per-connection API key.
 *
 * `public_id` is what integrations and people see (`conn_…`). Not the
 * auto-increment: that one counts every workspace's connections, so exposing
 * it tells a customer how many exist platform-wide and invites guessing a
 * neighbour's.
 *
 * `api_key` goes because the public API now has one credential — the
 * workspace's API key (table api_keys) — and requests name the connection by
 * `public_id`. ⚠️ Any integration still sending a connection key to
 * /v1/send-message stops working at this migration; the old keys cannot be
 * recovered afterwards, and they would not work anyway (the request now needs
 * `connection_id`).
 */
return new class extends Migration
{
    private const OLD_PERMISSION = 'connections.generate-api-key';

    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('public_id', 32)->nullable()->after('id');
        });

        // Generated here rather than through the model: a migration must keep
        // producing the same schema after the model changes shape.
        DB::table('connections')->whereNull('public_id')->orderBy('id')->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('connections')->where('id', $row->id)
                        ->update(['public_id' => 'conn_'.Str::lower(Str::random(16))]);
                }
            });

        Schema::table('connections', function (Blueprint $table) {
            $table->unique('public_id');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropUnique(['api_key']);
            $table->dropColumn('api_key');
        });

        Permission::where('name', self::OLD_PERMISSION)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('api_key')->nullable()->unique()->after('credentials');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });

        Permission::findOrCreate(self::OLD_PERMISSION, 'web');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
