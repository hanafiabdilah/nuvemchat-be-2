<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `mcp.connect` — connect an LLM client (Claude, Codex) to this workspace.
 *
 * Its own permission rather than a rider on anything else, because what it
 * grants is a *credential that acts as you*: an MCP connection can do whatever
 * its holder can do, from a program, without anyone watching. Deciding who may
 * hand one out is a separate decision from anything the connection then reaches.
 *
 * Deliberately not named under `connections.*` — `connections.oauth` already
 * exists and means "link a Meta or TikTok account to a channel", which is a
 * different thing entirely.
 *
 * Note what this permission does NOT do: it does not widen anybody. A
 * connection is bound to the person who approved it, and every tool re-reads
 * that person's own permissions on every call. Granting `mcp.connect` to an
 * agent who cannot edit flows gives them an MCP client that cannot edit flows.
 *
 * Declared in RoleAndPermissionSeeder too, but deploys only run
 * `migrate --force`, so existing owners get it here.
 */
return new class extends Migration
{
    private const PERMISSION = 'mcp.connect';

    public function up(): void
    {
        Permission::findOrCreate(self::PERMISSION, 'web');

        Role::where('name', 'owner')->get()->each(
            fn (Role $role) => $role->givePermissionTo(self::PERMISSION)
        );

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
