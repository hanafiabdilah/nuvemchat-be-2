<?php

namespace Tests\Support;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Flow\NodeType;
use App\Models\Connection;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\Incident;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Incident\IncidentService;
use App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Setup for the incident suite: a WhatsApp Official connection whose flow
 * greets ("Olá! Sou o Darwin."), so a test can tell the flow answering from
 * the incident answering by the words alone.
 */
final class IncidentFixtures
{
    public const WA_ID = '5511999990001';

    public const GREETING = 'Olá! Sou o Darwin.';

    public static function owner(): User
    {
        $user = User::factory()->create(['whatsapp_verified_at' => now()]);
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        Role::findOrCreate('owner', 'web');
        $user->assignRole('owner');
        $user->givePermissionTo([
            Permission::findOrCreate('incidents.view', 'web'),
            Permission::findOrCreate('incidents.manage', 'web'),
        ]);

        return $user->fresh();
    }

    public static function connection(User $owner, string $phoneNumberId = '106540352242922', string $name = 'Suporte'): Connection
    {
        $flow = Flow::create(['tenant_id' => $owner->tenant_id, 'name' => 'Atendimento '.$phoneNumberId]);
        $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
        $hello = $flow->nodes()->create([
            'type' => NodeType::Message,
            'data' => ['body' => self::GREETING, 'message_type' => 'text'],
            'position_x' => 200,
            'position_y' => 0,
        ]);
        FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $hello->id, 'condition_value' => null]);

        return Connection::create([
            'tenant_id' => $owner->tenant_id,
            'channel' => Channel::WhatsappOfficial,
            'name' => $name,
            'status' => ConnectionStatus::Active,
            'flow_id' => $flow->id,
            'credentials' => ['phone_number_id' => $phoneNumberId, 'access_token' => 'test-token', 'business_account_id' => '111'],
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function incident(User $owner, Connection $connection, array $overrides = []): Incident
    {
        $data = app(IncidentService::class)->validate(array_merge([
            'title' => 'DNS ProxyBR',
            'connection_ids' => [$connection->id],
            'match_mode' => 'all',
            'message' => 'Olá {{contact.first_name}}! Identificamos uma instabilidade de DNS.',
            'duration_minutes' => 180,
        ], $overrides));

        return app(IncidentService::class)->create($owner->tenant, $data, $owner);
    }

    /** One Cloud API webhook entry carrying a customer's text. */
    public static function text(string $body, string $phoneNumberId = '106540352242922', string $waId = self::WA_ID, string $name = 'Maria Souza'): array
    {
        return self::entry(['type' => 'text', 'text' => ['body' => $body]], $phoneNumberId, $waId, $name);
    }

    public static function buttonTap(string $id, string $title, string $phoneNumberId = '106540352242922'): array
    {
        return self::entry([
            'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]],
        ], $phoneNumberId);
    }

    /** @param  array<string, mixed>  $message */
    public static function entry(array $message, string $phoneNumberId = '106540352242922', string $waId = self::WA_ID, string $name = 'Maria Souza'): array
    {
        return [
            'id' => '111',
            'changes' => [[
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '551140000000', 'phone_number_id' => $phoneNumberId],
                    'contacts' => [['profile' => ['name' => $name], 'wa_id' => $waId]],
                    'messages' => [$message + [
                        'from' => $waId,
                        'id' => 'wamid.IN'.uniqid('', true),
                        'timestamp' => (string) now()->timestamp,
                    ]],
                ],
                'field' => 'messages',
            ]],
        ];
    }

    public static function deliver(Connection $connection, array $entry): void
    {
        (new WhatsappOfficialHandler)->handle($connection->fresh(), $entry);
    }
}
