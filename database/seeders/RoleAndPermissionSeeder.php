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

            // Broadcast campaigns. `send` is split from `create` on purpose:
            // drafting a blast and firing it at thousands of customers are
            // different levels of trust.
            'broadcasts.view',
            'broadcasts.create',
            'broadcasts.send',
            'broadcasts.delete',

            // Sales funnel. Reshaping the funnel is split from working a card:
            // moving a lead is daily work, deciding what counts as a sale is not.
            'leads.view',
            'leads.create',
            'leads.update',
            'leads.delete',
            'lead-pipelines.manage',

            // Instagram publishing. `delete` covers our own drafts and
            // schedules only — Instagram Login has no endpoint for removing a
            // post that is already live.
            'instagram-posts.view',
            'instagram-posts.create',
            'instagram-posts.publish',
            'instagram-posts.delete',
            'instagram-comments.manage',

            // Virtual numbers rented from API Way. `view` reads the codes that
            // arrive (an OTP is a credential); `manage` spends the prepaid
            // balance and cancels a rental somebody may still be waiting on.
            'numbers.view',
            'numbers.manage',
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

        // Platform-level Back Office admin role. Access is gated by being an
        // App\Models\Admin holding a platform role (EnsureUserIsSuperAdmin),
        // so it does not need the tenant-scoped permissions above.
        Role::firstOrCreate(
            ['name' => 'super-admin'],
            ['guard_name' => 'web']
        );

        $this->command->info('Roles and permissions created successfully!');
    }
}
