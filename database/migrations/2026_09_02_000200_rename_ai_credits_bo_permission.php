<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `bo.ai-credits.manage` → `bo.credits.manage`.
 *
 * The permission follows the wallet it guards: the balance it lets support comp
 * or claw back now also pays for API Way instances, so naming it after AI would
 * describe the smaller half of what it opens.
 *
 * Renamed in place rather than created-and-deleted. The old row is what every
 * existing super-admin actually holds, and dropping it to insert a new one
 * would take the Back Office credits page away from everyone until the seeder
 * ran again — which deploys do not do, they run `migrate --force`.
 */
return new class extends Migration
{
    private const OLD = 'bo.ai-credits.manage';

    private const NEW = 'bo.credits.manage';

    public function up(): void
    {
        $this->rename(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rename(self::NEW, self::OLD);
    }

    /**
     * Move the name across, whichever state the database is in.
     *
     * Three cases, and all three have to land on "the target exists and
     * super-admin holds it": the source is there (rename it), the target is
     * already there (nothing to move), or neither is (a database that predates
     * the credits feature — create it).
     */
    private function rename(string $from, string $to): void
    {
        $source = Permission::where('name', $from)->where('guard_name', 'web')->first();
        $target = Permission::where('name', $to)->where('guard_name', 'web')->first();

        if ($source && ! $target) {
            $source->update(['name' => $to]);
            $target = $source;
        }

        $target ??= Permission::create([
            'name' => $to,
            'guard_name' => 'web',
            'is_platform' => true,
        ]);

        if ($source && $source->isNot($target)) {
            // Both names existed. Keep the target, drop the duplicate — leaving
            // it behind means half the admins are checked against a permission
            // no route mentions any more.
            $source->delete();
        }

        // super-admin is defined as "every platform permission", so it has to be
        // topped up rather than left holding the name that just disappeared.
        Role::where('name', 'super-admin')->where('is_platform', true)->get()->each(
            fn (Role $role) => $role->givePermissionTo($target)
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
