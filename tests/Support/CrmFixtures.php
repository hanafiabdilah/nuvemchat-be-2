<?php

namespace Tests\Support;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Shared setup for the CRM suites — a class, so Pest's single process never
 * sees two files defining the same helper.
 */
class CrmFixtures
{
    public const EDITOR = [
        'customers.view',
        'customers.update',
        'companies.view',
        'companies.manage',
        'contacts.update',
    ];

    public const ALL = [
        ...self::EDITOR,
        'customers.merge',
        'customers.delete',
        'crm-settings.manage',
    ];

    /** Phase 2: leads, deals, tasks and activity — as every role receives them. */
    public const SALES = [
        'customers.view',
        'customers.update',
        'leads.view',
        'leads.manage',
        'deals.view',
        'deals.manage',
        'crm-tasks.manage',
        'crm-activities.manage',
    ];

    /** Phase 3 on top of everything: segments, reports, data in and out. */
    public const INSIGHTS = [
        'segments.manage',
        'crm-reports.view',
        'crm-data.export',
        'crm-data.import',
        'crm-settings.manage',
    ];

    /** The sales permissions only the owner role starts with. */
    public const SALES_ADMIN = [
        ...self::SALES,
        'leads.delete',
        'deals.delete',
        'pipelines.manage',
        'customers.merge',
        'customers.delete',
    ];

    /** A workspace and a member holding these permissions (no role named owner). */
    public static function user(array $permissions = self::ALL, ?Tenant $tenant = null, string $name = 'Ana'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $tenant ??= Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        $role = Role::query()->create(['name' => 'crm-'.$user->id, 'guard_name' => 'web', 'tenant_id' => $tenant->id]);

        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $user->assignRole($role);

        return $user->fresh();
    }

    public static function connection(User $user, Channel $channel = Channel::WhatsappOfficial, string $name = 'Toko'): Connection
    {
        $connection = Connection::create([
            'tenant_id' => $user->tenant_id,
            'channel' => $channel,
            'name' => $name,
            'color' => '#22c55e',
            'status' => ConnectionStatus::Active,
            'credentials' => ['access_token' => 'x'],
        ]);

        $user->connections()->syncWithoutDetaching([$connection->id]);

        return $connection;
    }

    /** A contact as a webhook makes one — through createFromExternalData. */
    public static function contact(Connection $connection, string $externalId, string $name = 'Budi Santoso'): Contact
    {
        return Contact::createFromExternalData($connection, $externalId, $name)->fresh();
    }

    public static function conversation(Contact $contact, Connection $connection, array $overrides = []): Conversation
    {
        return Conversation::create(array_merge([
            'contact_id' => $contact->id,
            'connection_id' => $connection->id,
            'external_id' => $contact->external_id,
            'status' => ConversationStatus::Pending,
            'last_message_at' => now(),
        ], $overrides));
    }
}
