<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // Contacts
            'contacts.update',

            // Connections
            'connections.create',
            'connections.update',
            'connections.connect',
            'connections.oauth',
            'connections.check-status',
            'connections.generate-api-key',
            'connections.disconnect',
            'connections.delete',
            'connections.update-automated-messages',

            // Tags
            'tags.create',
            'tags.update',
            'tags.delete',

            // Agents
            'agents.view',
            'agents.create',
            'agents.update',
            'agents.delete',
            'agents.sync-connections',
            'agents.assign-roles',
            'agents.assign-permissions',

            // Roles
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',

            // Flow
            'flows.view',
            'flows.create',
            'flows.update',
            'flows.delete',

            // AI Agents
            'ai-agents.view',
            'ai-agents.create',
            'ai-agents.update',
            'ai-agents.delete',

            // Statistics
            'statistics.tenant.view',
            'statistics.agents.view',

            // Billing (tenant-side subscription management)
            'billing.view',
            'billing.manage',

            // Service hours (business hours that gate AI → human handoff)
            'service-hours.view',
            'service-hours.update',

            // WhatsApp message templates (Cloud API)
            'templates.view',
            'templates.create',
            'templates.delete',
            'templates.send',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['guard_name' => 'web']
            );
        }

        // Create owner role only (other roles will be created dynamically)
        $ownerRole = Role::firstOrCreate(
            ['name' => 'owner'],
            ['guard_name' => 'web']
        );

        // Assign all permissions to owner role
        $ownerRole->syncPermissions(Permission::all());

        // Platform-level Back Office admin role. Access is gated by the
        // `super-admin` role + null tenant_id (EnsureUserIsSuperAdmin),
        // so it does not need the tenant-scoped permissions above.
        Role::firstOrCreate(
            ['name' => 'super-admin'],
            ['guard_name' => 'web']
        );

        $this->command->info('Roles and permissions created successfully!');
    }
}
