<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Http\Controllers\Api\ConversationController;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\ConversationObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Removing a person must never remove what they worked on.
 *
 * The user row used to be deleted and the schema did the rest:
 * `conversations.user_id` cascades, so every conversation the person had ever
 * been assigned — resolved ones included — went with them, messages and all.
 */
function removalOwner(string $name = 'Marina Dona'): User
{
    $owner = User::factory()->create(['name' => $name]);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('owner', 'web');
    foreach (['agents.view', 'agents.delete'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $owner->assignRole($role);

    return $owner->fresh();
}

function removalConnection(User $owner, Channel $channel = Channel::WhatsappApiway, string $name = 'Vendas'): Connection
{
    return Connection::create([
        'tenant_id' => $owner->tenant_id,
        'channel' => $channel,
        'name' => $name,
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);
}

function removalAgent(User $owner, array $connections, string $name): User
{
    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id, 'name' => $name]);
    $agent->connections()->syncWithoutDetaching(collect($connections)->pluck('id')->all());

    return $agent->fresh();
}

function removalConversation(Connection $connection, ?User $assignee, ConversationStatus $status, string $externalId): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => $externalId,
        'name' => 'Cliente '.$externalId,
        'channel' => $connection->channel,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'user_id' => $assignee?->id,
        'external_id' => $externalId,
        'type' => ConversationType::Private,
        'status' => $status,
    ]);
}

beforeEach(function () {
    // Only the broadcasts: model events stay real, so ConversationObserver
    // still writes its status note.
    Event::fake([ConversationUpdated::class, MessageReceived::class]);
});

test('deleting an attendant keeps their resolved conversations and every message in them', function () {
    $owner = removalOwner();
    $connection = removalConnection($owner);
    $agent = removalAgent($owner, [$connection], 'Bruna');
    $conversation = removalConversation($connection, $agent, ConversationStatus::Resolved, '5511900000001');

    $conversation->messages()->create([
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Oi, meu pedido chegou?',
        'sent_at' => now(),
    ]);
    $conversation->messages()->create([
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Text,
        'body' => 'Chegou sim!',
        'sent_at' => now(),
        'sent_by_user_id' => $agent->id,
    ]);

    $this->actingAs($owner, 'sanctum')
        ->deleteJson("/api/agents/{$agent->id}")
        ->assertOk()
        ->assertJsonPath('data.kept_in_history', 1);

    expect(User::find($agent->id))->toBeNull();

    $kept = Conversation::find($conversation->id);
    expect($kept)->not->toBeNull()
        ->and($kept->user_id)->toBeNull()
        ->and($kept->status)->toBe(ConversationStatus::Resolved)
        ->and($kept->messages()->count())->toBe(2)
        ->and($kept->messages()->whereNotNull('sent_by_user_id')->count())->toBe(0);
});

test('open conversations go back to the queue by default, with the usual status note', function () {
    $owner = removalOwner('Marina Dona');
    $connection = removalConnection($owner);
    $agent = removalAgent($owner, [$connection], 'Bruna');
    $conversation = removalConversation($connection, $agent, ConversationStatus::Active, '5511900000002');

    $this->actingAs($owner, 'sanctum')
        ->deleteJson("/api/agents/{$agent->id}")
        ->assertOk()
        ->assertJsonPath('data.returned_to_queue', 1)
        ->assertJsonPath('data.transferred', 0);

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->user_id)->toBeNull();

    $note = $conversation->messages()->where('message_type', MessageType::Info)->sole();
    expect($note->meta['info']['code'])->toBe(ConversationObserver::INFO_STATUS_CHANGED_BY)
        ->and($note->meta['info']['params'])->toMatchArray([
            'from_status' => 'active',
            'to_status' => 'pending',
            'by' => 'Marina Dona',
        ]);

    Event::assertDispatched(ConversationUpdated::class, fn ($event) => $event->conversation->id === $conversation->id);
});

test('open conversations go to the chosen attendant where that person can reach them', function () {
    $owner = removalOwner('Marina Dona');
    $sales = removalConnection($owner, name: 'Vendas');
    $support = removalConnection($owner, name: 'Suporte');
    $agent = removalAgent($owner, [$sales, $support], 'Bruna');
    $target = removalAgent($owner, [$sales], 'Carlos');

    $reachable = removalConversation($sales, $agent, ConversationStatus::Active, '5511900000003');
    $unreachable = removalConversation($support, $agent, ConversationStatus::Active, '5511900000004');

    $this->actingAs($owner, 'sanctum')
        ->deleteJson("/api/agents/{$agent->id}?reassign_to={$target->id}")
        ->assertOk()
        ->assertJsonPath('data.transferred', 1)
        ->assertJsonPath('data.returned_to_queue', 1);

    $reachable->refresh();
    expect($reachable->user_id)->toBe($target->id)
        ->and($reachable->status)->toBe(ConversationStatus::Active);

    $note = $reachable->messages()->where('message_type', MessageType::Info)->sole();
    expect($note->meta['info']['code'])->toBe(ConversationController::INFO_TRANSFERRED)
        ->and($note->meta['info']['params'])->toBe(['from' => 'Marina Dona', 'to' => 'Carlos']);

    // Carlos cannot see Suporte, so handing it to him would strand it.
    $unreachable->refresh();
    expect($unreachable->user_id)->toBeNull()
        ->and($unreachable->status)->toBe(ConversationStatus::Pending);
});

test('the receiver has to be someone else in the same workspace', function () {
    $owner = removalOwner();
    $connection = removalConnection($owner);
    $agent = removalAgent($owner, [$connection], 'Bruna');
    $stranger = removalOwner('Outra Empresa');

    $this->actingAs($owner, 'sanctum')
        ->deleteJson("/api/agents/{$agent->id}?reassign_to={$stranger->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reassign_to');

    $this->actingAs($owner, 'sanctum')
        ->deleteJson("/api/agents/{$agent->id}?reassign_to={$agent->id}")
        ->assertUnprocessable();

    expect(User::find($agent->id))->not->toBeNull();
});

test('an e-mail conversation only loses its assignee', function () {
    $owner = removalOwner();
    $inbox = removalConnection($owner, Channel::Email, 'Contato');
    $agent = removalAgent($owner, [$inbox], 'Bruna');
    $conversation = removalConversation($inbox, $agent, ConversationStatus::Active, 'cliente@example.com');

    $this->actingAs($owner, 'sanctum')->deleteJson("/api/agents/{$agent->id}")->assertOk();

    $conversation->refresh();
    expect($conversation->user_id)->toBeNull()
        ->and($conversation->status)->toBe(ConversationStatus::Active);
});

test('campaigns and instagram posts outlive the person who created them', function () {
    $owner = removalOwner();
    $connection = removalConnection($owner);
    $agent = removalAgent($owner, [$connection], 'Bruna');

    $broadcastId = DB::table('broadcasts')->insertGetId([
        'tenant_id' => $owner->tenant_id,
        'connection_id' => $connection->id,
        'created_by' => $agent->id,
        'name' => 'Black Friday',
        'status' => 'completed',
        'content_type' => 'text',
        'payload' => json_encode(['body' => 'Oi']),
        'rate_per_minute' => 30,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $postId = DB::table('instagram_posts')->insertGetId([
        'tenant_id' => $owner->tenant_id,
        'connection_id' => $connection->id,
        'created_by' => $agent->id,
        'status' => 'scheduled',
        'media_type' => 'image',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($owner, 'sanctum')->deleteJson("/api/agents/{$agent->id}")->assertOk();

    expect(DB::table('broadcasts')->where('id', $broadcastId)->value('created_by'))->toBeNull()
        ->and(DB::table('broadcasts')->where('id', $broadcastId)->exists())->toBeTrue()
        ->and(DB::table('instagram_posts')->where('id', $postId)->exists())->toBeTrue()
        ->and(DB::table('instagram_posts')->where('id', $postId)->value('created_by'))->toBeNull();
});

test('removing someone revokes their sessions', function () {
    $owner = removalOwner();
    $agent = removalAgent($owner, [], 'Bruna');
    $agent->createToken('auth_token');

    $this->actingAs($owner, 'sanctum')->deleteJson("/api/agents/{$agent->id}")->assertOk();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $agent->id)->count())->toBe(0);
});

test('the owner cannot be deleted', function () {
    $owner = removalOwner();

    $this->actingAs($owner, 'sanctum')->deleteJson("/api/agents/{$owner->id}")->assertForbidden();

    expect(User::find($owner->id))->not->toBeNull();
});

test('the attendants list says how many open conversations each person holds', function () {
    $owner = removalOwner();
    $connection = removalConnection($owner);
    $agent = removalAgent($owner, [$connection], 'Bruna');

    removalConversation($connection, $agent, ConversationStatus::Active, '5511900000010');
    removalConversation($connection, $agent, ConversationStatus::Pending, '5511900000011');
    removalConversation($connection, $agent, ConversationStatus::Resolved, '5511900000012');

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/agents')
        ->assertOk()
        ->assertJsonPath("open_conversations.{$agent->id}", 2);
});
