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

        $this->command->info('Roles and permissions created successfully!');
    }
}
