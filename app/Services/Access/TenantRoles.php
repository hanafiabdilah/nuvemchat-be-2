<?php

namespace App\Services\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Roles, the way one workspace is allowed to see them.
 *
 * `roles.tenant_id` null is a global role: the Back Office's platform roles
 * (is_platform) and the system roles listed below. Every other role belongs to
 * one workspace, and nothing outside it may list, edit, delete or assign it.
 * Spatie's own teams feature is deliberately not used — it re-keys every pivot
 * and every permission check by a per-request team id, for a problem one column
 * answers.
 *
 * ⚠️ Names are only unique inside a workspace now, so never resolve a tenant
 * role by name alone: `assignRole('Supervisor')` goes through Spatie's
 * findByName, which returns whichever "Supervisor" the database hands back
 * first. Resolve to models here, then pass the models on. The same goes for
 * `Role::create()`, which refuses a name that exists in *any* workspace — use
 * `Role::query()->create()` after validating uniqueness per workspace.
 */
final class TenantRoles
{
    /** Global roles every workspace sees, and none can edit, delete or hand out. */
    public const SYSTEM_ROLES = ['owner'];

    /** The roles one workspace may see: its own, plus the system roles. */
    public static function visibleTo(int $tenantId): Builder
    {
        return Role::query()
            ->where('is_platform', false)
            ->where(fn (Builder $query) => $query
                ->where('tenant_id', $tenantId)
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('tenant_id')
                    ->whereIn('name', self::SYSTEM_ROLES)));
    }

    /** The roles one workspace owns — the only ones it may change or assign. */
    public static function ownedBy(int $tenantId): Builder
    {
        return Role::query()
            ->where('is_platform', false)
            ->where('tenant_id', $tenantId);
    }

    public static function isSystem(Role $role): bool
    {
        return $role->getAttribute('tenant_id') === null;
    }

    /**
     * Names no workspace may give its own role: the system roles and every
     * platform role. Case-insensitive, because `hasRole('owner')` is what lets
     * someone see every connection, and the dashboard treats "Owner" as the
     * same role; and a workspace role sharing a platform role's name is the one
     * thing that could make a by-name lookup in the Back Office pick it up.
     */
    public static function isReservedName(string $name): bool
    {
        $wanted = mb_strtolower(trim($name));

        if (in_array($wanted, self::SYSTEM_ROLES, true)) {
            return true;
        }

        return Role::query()
            ->where('is_platform', true)
            ->pluck('name')
            ->contains(fn (string $platform) => mb_strtolower($platform) === $wanted);
    }

    /**
     * The workspace's roles with these names, or a validation error on the
     * field — so an unknown name, another workspace's role and a platform role
     * all fail the same way instead of being silently dropped.
     *
     * @param  array<int, mixed>  $names
     * @return Collection<int, Role>
     */
    public static function resolve(int $tenantId, array $names, string $field = 'roles'): Collection
    {
        $names = collect($names)
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return collect();
        }

        $roles = self::ownedBy($tenantId)->whereIn('name', $names->all())->get();

        if ($roles->count() !== $names->count()) {
            throw ValidationException::withMessages([$field => 'The selected role is invalid.']);
        }

        return $roles;
    }
}
