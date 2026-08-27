<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** @param list<string> $permissions */
function liveAdmin(array $permissions = ['bo.live.view']): User
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user = User::factory()->create(['tenant_id' => null]);
    $user->assignRole($role);

    return $user->fresh();
}

function liveWorkspace(string $ownerName = 'Acme Ltda'): array
{
    $owner = User::factory()->create(['name' => $ownerName]);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'name' => 'WA Vendas',
        'channel' => 'whatsapp_official',
        'status' => 'active',
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => '5511987654321',
        'name' => 'João Pereira',
    ]);

    $conversation = Conversation::create([
        'tenant_id' => $tenant->id,
        'connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Pending,
        'last_message_at' => now(),
    ]);

    return [$tenant, $connection, $conversation, $owner->fresh()];
}

function liveAdminMessage(Conversation $conversation, SenderType $sender, array $attributes = []): Message
{
    return Message::create(array_merge([
        'conversation_id' => $conversation->id,
        'sender_type' => $sender,
        'message_type' => MessageType::Text,
        'body' => 'conteúdo privado do cliente',
        'sent_at' => now(),
    ], $attributes))->fresh();
}

it('refuses a platform admin without the permission', function () {
    $admin = liveAdmin([]);

    $this->actingAs($admin)->getJson('/api/admin/live')->assertForbidden();
});

it('answers with an empty platform', function () {
    $admin = liveAdmin();

    $data = $this->actingAs($admin)->getJson('/api/admin/live')->assertOk()->json('data');

    expect($data['events'])->toBe([])
        ->and($data['agents'])->toBe([])
        ->and($data['tenants'])->toBe([])
        ->and($data['pulse']['series'])->toHaveCount(15);
});

it('masks the customer but names the workspace', function () {
    $admin = liveAdmin();
    [$tenant, , $conversation] = liveWorkspace();

    liveAdminMessage($conversation, SenderType::Incoming);

    $response = $this->actingAs($admin)->getJson('/api/admin/live')->assertOk();
    $event = $response->json('data.events.0');

    expect($response->getContent())->not->toContain('conteúdo privado do cliente')
        // Enough to tell two rows apart and read one back over the phone;
        // not a contact list of another company's customers.
        ->and($event['contact']['name'])->toBe('João P.')
        ->and($event['contact']['handle'])->toBe('••••••4321')
        ->and($event['actor']['name'])->toBe('João P.')
        // Which workspace is on fire is the entire point, so this is not masked.
        ->and($event['tenant_id'])->toBe($tenant->id)
        ->and($event['tenant_name'])->toBe('Acme Ltda')
        ->and($event['connection']['name'])->toBe('WA Vendas');
});

it('narrows to one workspace when asked', function () {
    $admin = liveAdmin();
    [$acme, , $acmeThread] = liveWorkspace('Acme Ltda');
    [, , $otherThread] = liveWorkspace('Globex SA');

    liveAdminMessage($acmeThread, SenderType::Incoming);
    liveAdminMessage($otherThread, SenderType::Incoming);

    $all = $this->actingAs($admin)->getJson('/api/admin/live')->assertOk()->json('data.events');
    $one = $this->actingAs($admin)->getJson("/api/admin/live?tenant_id={$acme->id}")->assertOk()->json('data.events');

    expect($all)->toHaveCount(2)
        ->and($one)->toHaveCount(1)
        ->and($one[0]['tenant_name'])->toBe('Acme Ltda');
});

it('lists only agents who are actually working, across every workspace', function () {
    $admin = liveAdmin();
    [$tenant, , $conversation, $owner] = liveWorkspace();

    $working = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Ana Souza',
        'last_seen_at' => now(),
    ]);
    // Signed in yesterday and gone since: not part of "who is online".
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Bruno Lima',
        'last_seen_at' => now()->subDay(),
    ]);

    $conversation->update(['user_id' => $working->id, 'status' => ConversationStatus::Active]);
    liveAdminMessage($conversation, SenderType::Outgoing, ['sent_by_user_id' => $working->id]);

    $agents = $this->actingAs($admin)->getJson('/api/admin/live')->assertOk()->json('data.agents');

    expect($agents)->toHaveCount(1)
        ->and($agents[0]['name'])->toBe('Ana Souza')
        ->and($agents[0]['presence'])->toBe('active')
        ->and($agents[0]['tenant_name'])->toBe('Acme Ltda')
        ->and($agents[0]['open_conversations'])->toBe(1)
        // The customer this agent is answering is masked here too.
        ->and($agents[0]['handling']['contact'])->toBe('João P.')
        // Staff e-mail addresses are not part of the wallboard's job.
        ->and($agents[0]['email'])->toBeNull()
        ->and($owner->name)->toBe('Acme Ltda');
});

it('ranks the workspaces that are actually moving', function () {
    $admin = liveAdmin();
    [$busy, , $busyThread] = liveWorkspace('Acme Ltda');
    [, , $quietThread] = liveWorkspace('Globex SA');

    liveAdminMessage($busyThread, SenderType::Incoming);
    liveAdminMessage($busyThread, SenderType::Outgoing);
    liveAdminMessage($quietThread, SenderType::Incoming);

    $tenants = $this->actingAs($admin)->getJson('/api/admin/live')->assertOk()->json('data.tenants');

    expect($tenants[0]['tenant_id'])->toBe($busy->id)
        ->and($tenants[0]['messages'])->toBe(2)
        ->and($tenants[0]['inbound'])->toBe(1)
        ->and($tenants[0]['outbound'])->toBe(1)
        ->and($tenants[1]['tenant_name'])->toBe('Globex SA');
});

it('streams deltas by cursor without recomputing the aggregates', function () {
    $admin = liveAdmin();
    [, , $conversation] = liveWorkspace();

    $first = liveAdminMessage($conversation, SenderType::Incoming);
    $cursor = $this->actingAs($admin)->getJson('/api/admin/live')->assertOk()->json('data.cursor');

    expect($cursor)->toBe($first->id);

    $second = liveAdminMessage($conversation, SenderType::Outgoing);

    $delta = $this->actingAs($admin)->getJson("/api/admin/live?after_id={$cursor}")->assertOk()->json('data');

    expect($delta['events'])->toHaveCount(1)
        ->and($delta['events'][0]['id'])->toBe($second->id)
        ->and($delta)->not->toHaveKey('agents')
        ->and($delta)->not->toHaveKey('pulse');
});
