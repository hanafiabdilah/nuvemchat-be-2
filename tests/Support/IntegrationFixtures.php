<?php

namespace Tests\Support;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Shared setup for the integrations suite and the three flow nodes built on it
 * (payment, pixel, go-to-flow).
 *
 * A class rather than Pest helper functions because those are global: two test
 * files each defining `fixture()` is a fatal "cannot redeclare" the moment the
 * whole suite runs, and nothing flags it while a single file is run alone.
 */
final class IntegrationFixtures
{
    /** A syntactically plausible Pix "BR Code" — the QR encodes exactly this. */
    public const PIX_CODE = '00020101021226880014br.gov.bcb.pix2566qrcodes-pix.example.com/v2/cobv/9d36b84f52040000530398654050049905802BR5911LOJA TESTE6009SAO PAULO62070503***6304ABCD';

    public const CREDENTIALS = [
        'openpix' => ['app_id' => 'Q2xpZW50X0lkXzEyMzQ1Njc4OTA6Q2xpZW50X1NlY3JldA=='],
        'mercadopago' => ['access_token' => 'APP_USR-1234567890123456-091000-abcdefabcdefabcdef-123456789'],
        'meta_pixel' => ['access_token' => 'EAABsbCS1iHgBAKZCZBtestTokenValue1234567890'],
        'google_analytics' => ['api_secret' => 'gA4sEcReT_value_123'],
    ];

    public const SETTINGS = [
        'openpix' => ['sandbox' => false],
        'mercadopago' => [],
        'meta_pixel' => ['pixel_id' => '123456789012345'],
        'google_analytics' => ['measurement_id' => 'G-TEST12345'],
    ];

    public static function tenant(): Tenant
    {
        $owner = User::factory()->create(['whatsapp_verified_at' => now()]);
        $tenant = Tenant::create(['user_id' => $owner->id]);
        $owner->forceFill(['tenant_id' => $tenant->id])->save();

        return $tenant;
    }

    /**
     * A member of $tenant (a new workspace when null) holding exactly these
     * permissions, through a role of their own.
     *
     * @param  list<string>  $permissions
     */
    public static function user(?Tenant $tenant = null, array $permissions = ['integrations.view', 'integrations.manage', 'flows.update']): User
    {
        $tenant ??= self::tenant();

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'whatsapp_verified_at' => now()]);

        $role = Role::findOrCreate('integrations-test-'.$user->id, 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $attributes */
    public static function integration(Tenant $tenant, IntegrationProvider $provider, array $attributes = []): Integration
    {
        return Integration::create(array_merge([
            'tenant_id' => $tenant->id,
            'provider' => $provider,
            'name' => $provider->label(),
            'credentials' => self::CREDENTIALS[$provider->value],
            'settings' => self::SETTINGS[$provider->value],
            'meta' => [],
            'enabled' => true,
            'verified_at' => now(),
        ], $attributes));
    }

    public static function flow(Tenant $tenant, string $name = 'Checkout'): Flow
    {
        $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => $name]);
        $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

        return $flow;
    }

    public static function start(Flow $flow): FlowNode
    {
        return FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Start)->firstOrFail();
    }

    /** @param  array<string, mixed>|null  $data */
    public static function node(Flow $flow, NodeType $type, ?array $data, int $x = 280, int $y = 0): FlowNode
    {
        return $flow->nodes()->create([
            'type' => $type,
            'data' => $data,
            'position_x' => $x,
            'position_y' => $y,
        ]);
    }

    /** A message node that sends one text and moves on — how tests read which branch ran. */
    public static function say(Flow $flow, string $body, int $x = 560, int $y = 0): FlowNode
    {
        return self::node($flow, NodeType::Message, [
            'body' => $body,
            'message_type' => 'text',
            'wait_for_reply' => false,
        ], $x, $y);
    }

    public static function edge(FlowNode $from, FlowNode $to, ?string $branch = null): void
    {
        FlowEdge::create([
            'source_node_id' => $from->id,
            'target_node_id' => $to->id,
            'condition_value' => $branch,
        ]);
    }

    /** A WhatsApp Official conversation, Pending, on a connection driven by $flow. */
    public static function conversation(Tenant $tenant, Flow $flow, string $phone = '5511999999999', string $name = 'Maria Souza'): Conversation
    {
        $connection = Connection::create([
            'tenant_id' => $tenant->id,
            'channel' => Channel::WhatsappOfficial,
            'name' => 'WA',
            'color' => '#22c55e',
            'status' => ConnectionStatus::Active,
            'flow_id' => $flow->id,
            'credentials' => [
                'phone_number_id' => '111000111',
                'access_token' => 'wa-token',
                'business_account_id' => '222000222',
            ],
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'channel' => Channel::WhatsappOfficial,
            'external_id' => $phone,
            'name' => $name,
            'username' => $phone,
        ]);

        return Conversation::create([
            'contact_id' => $contact->id,
            'connection_id' => $connection->id,
            'external_id' => $phone,
            'status' => ConversationStatus::Pending,
        ]);
    }

    /** @return list<string> Bodies of the texts actually sent to the customer. */
    public static function sentTexts(Conversation $conversation): array
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('sender_type', SenderType::Outgoing)
            ->where('message_type', MessageType::Text)
            ->orderBy('id')
            ->pluck('body')
            ->all();
    }

    /** @return Collection<int, Message> The info notes carrying $code. */
    public static function notes(Conversation $conversation, string $code): Collection
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('message_type', MessageType::Info)
            ->get()
            ->filter(fn (Message $message) => ($message->meta['info']['code'] ?? null) === $code)
            ->values();
    }
}
