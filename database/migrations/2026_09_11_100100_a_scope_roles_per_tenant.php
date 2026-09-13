<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant roles become the tenant's own.
 *
 * Roles were one platform-wide list: `teams` is off in config/permission.php,
 * the roles endpoint listed every non-platform row, and names were unique
 * across the whole platform — so one workspace's "Supervisor" showed up in
 * every other workspace, could be handed to their agents, and blocked anyone
 * else from calling a role "Supervisor" at all.
 *
 * Spatie teams would have solved it too, but they re-key every pivot and every
 * permission check by a team id set per request — far more than this needs.
 * A plain `roles.tenant_id` does it: null means a global system role (only
 * `owner` today, plus the Back Office's platform roles), anything else belongs
 * to that workspace. The API scopes on it (App\Services\Access\TenantRoles).
 *
 * The backfill decides whose each existing role is from who holds it:
 *  - held by users of one workspace → that workspace's;
 *  - held by users of several → the first keeps the row, every other workspace
 *    gets its own copy (same name, same permissions) and its users are moved
 *    onto it, so nobody's access changes by a single permission;
 *  - held by nobody → nothing says whose it is. On a single-workspace install
 *    it is that workspace's; otherwise it stays global and is no longer shown
 *    to anyone (it grants nothing to anybody, so hiding it removes no access).
 *    Its id is logged so support can attach it if a customer asks for it.
 *
 * Also cleans up two leaks into tenant screens: `bo.*` permissions not flagged
 * as platform, and platform permissions held by tenant roles (the owner role
 * used to sync `Permission::all()`, which swept the Back Office's in).
 *
 * Online-safe: `roles` is a small table, the column is nullable (no rewrite of
 * existing rows' data), and nothing here touches messages or conversations.
 */
return new class extends Migration
{
    /** Global roles every workspace sees but none can edit or hand out. */
    private const SYSTEM_ROLES = ['owner'];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->index('tenant_id', 'roles_tenant_id_index');
        });

        // Before the backfill: the copies it makes share their original's name.
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasIndex('roles', 'roles_name_guard_name_unique')) {
                $table->dropUnique('roles_name_guard_name_unique');
            }

            $table->unique(['tenant_id', 'name', 'guard_name'], 'roles_tenant_id_name_guard_name_unique');
        });

        $this->flagPlatformRecords();
        $this->backfill();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Give every legacy global role to the workspace(s) whose people hold it.
     *
     * Public so the test suite can run it against rows it sets up — by the time
     * a test has data to seed, the migration itself has long run.
     */
    public function backfill(): void
    {
        $userType = (new User)->getMorphClass();
        $tenantIds = DB::table('tenants')->orderBy('id')->pluck('id');

        $roles = DB::table('roles')
            ->where('is_platform', false)
            ->whereNull('tenant_id')
            ->whereNotIn('name', self::SYSTEM_ROLES)
            ->orderBy('id')
            ->get();

        foreach ($roles as $role) {
            $holders = DB::table('model_has_roles')
                ->join('users', 'users.id', '=', 'model_has_roles.model_id')
                ->where('model_has_roles.role_id', $role->id)
                ->where('model_has_roles.model_type', $userType)
                ->whereNotNull('users.tenant_id')
                ->distinct()
                ->orderBy('users.tenant_id')
                ->pluck('users.tenant_id');

            if ($holders->isEmpty()) {
                if ($tenantIds->count() === 1) {
                    DB::table('roles')->where('id', $role->id)->update(['tenant_id' => $tenantIds->first()]);
                } else {
                    Log::info('Roles per tenant: a role nobody holds was left global and hidden', [
                        'role_id' => $role->id,
                        'name' => $role->name,
                    ]);
                }

                continue;
            }

            DB::table('roles')->where('id', $role->id)->update(['tenant_id' => $holders->first()]);

            $permissionIds = DB::table('role_has_permissions')->where('role_id', $role->id)->pluck('permission_id');

            foreach ($holders->slice(1) as $tenantId) {
                $copy = collect((array) $role)->except(['id', 'tenant_id'])->all();

                $cloneId = DB::table('roles')->insertGetId(array_merge($copy, [
                    'tenant_id' => $tenantId,
                    'updated_at' => now(),
                ]));

                if ($permissionIds->isNotEmpty()) {
                    DB::table('role_has_permissions')->insert(
                        $permissionIds->map(fn ($permissionId) => [
                            'permission_id' => $permissionId,
                            'role_id' => $cloneId,
                        ])->all()
                    );
                }

                DB::table('model_has_roles')
                    ->where('role_id', $role->id)
                    ->where('model_type', $userType)
                    ->whereIn('model_id', DB::table('users')->select('id')->where('tenant_id', $tenantId))
                    ->update(['role_id' => $cloneId]);
            }
        }
    }

    /**
     * Back Office records stay out of tenant screens.
     *
     * Every `bo.*` permission is a platform one, whatever the migration that
     * created it remembered to set; `super-admin` is the platform's role. And
     * a tenant role or a tenant user holding a platform permission is a
     * leftover — tenant users cannot reach the Back Office whatever they hold
     * (EnsureUserIsSuperAdmin gates on the Admin model), so dropping it only
     * stops it being printed in the workspace's role summaries.
     *
     * Public for the same reason as backfill().
     */
    public function flagPlatformRecords(): void
    {
        DB::table('permissions')->where('name', 'like', 'bo.%')->update(['is_platform' => true]);
        DB::table('roles')->where('name', 'super-admin')->update(['is_platform' => true]);

        $platformPermissionIds = DB::table('permissions')->where('is_platform', true)->pluck('id');

        if ($platformPermissionIds->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $platformPermissionIds)
            ->whereIn('role_id', DB::table('roles')->select('id')->where('is_platform', false))
            ->delete();

        DB::table('model_has_permissions')
            ->whereIn('permission_id', $platformPermissionIds)
            ->where('model_type', (new User)->getMorphClass())
            ->delete();
    }

    /**
     * Folds every per-workspace copy back into the oldest role of that name so
     * the old platform-wide unique index can return. Lossy by nature: two
     * workspaces that each made their own "Supervisor" end up sharing one, with
     * the union of both permission sets.
     */
    public function down(): void
    {
        $duplicates = DB::table('roles')
            ->select('name', 'guard_name', DB::raw('MIN(id) as keep_id'))
            ->groupBy('name', 'guard_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $group) {
            $others = DB::table('roles')
                ->where('name', $group->name)
                ->where('guard_name', $group->guard_name)
                ->where('id', '!=', $group->keep_id)
                ->pluck('id');

            foreach ($others as $otherId) {
                foreach (DB::table('model_has_roles')->where('role_id', $otherId)->get() as $row) {
                    $exists = DB::table('model_has_roles')
                        ->where('role_id', $group->keep_id)
                        ->where('model_id', $row->model_id)
                        ->where('model_type', $row->model_type)
                        ->exists();

                    if (! $exists) {
                        DB::table('model_has_roles')->insert([
                            'role_id' => $group->keep_id,
                            'model_id' => $row->model_id,
                            'model_type' => $row->model_type,
                        ]);
                    }
                }

                $kept = DB::table('role_has_permissions')->where('role_id', $group->keep_id)->pluck('permission_id');
                $missing = DB::table('role_has_permissions')
                    ->where('role_id', $otherId)
                    ->whereNotIn('permission_id', $kept)
                    ->pluck('permission_id');

                if ($missing->isNotEmpty()) {
                    DB::table('role_has_permissions')->insert(
                        $missing->map(fn ($id) => ['permission_id' => $id, 'role_id' => $group->keep_id])->all()
                    );
                }
            }

            DB::table('roles')->whereIn('id', $others)->delete();
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_tenant_id_name_guard_name_unique');
            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
            $table->dropIndex('roles_tenant_id_index');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
