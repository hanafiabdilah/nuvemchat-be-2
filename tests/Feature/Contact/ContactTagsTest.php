<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ContactTagsUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function contactTagsUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('owner', 'web');
    $role->givePermissionTo(Permission::findOrCreate('contacts.update', 'web'));
    $user->assignRole($role);

    return $user->fresh();
}

function contactTagsConnection(User $user): Connection
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    $user->connections()->syncWithoutDetaching([$connection->id]);

    return $connection;
}

function contactTagsContact(User $user, string $externalId = '5511999999999'): Contact
{
    return Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => $externalId,
        'name' => 'Ana',
        'channel' => Channel::WhatsappApiway,
    ]);
}

function contactTagsConversation(Connection $connection, Contact $contact, ConversationStatus $status = ConversationStatus::Active): Conversation
{
    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => $status,
    ]);
}

function contactTag(User $user, string $name = 'VIP'): Tag
{
    return Tag::create([
        'tenant_id' => $user->tenant_id,
        'name' => $name,
        'color' => '#2563eb',
    ]);
}

test('tags can be attached to a contact and are returned with it', function () {
    Event::fake();
    $user = contactTagsUser();
    $contact = contactTagsContact($user);
    $tag = contactTag($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/contacts/{$contact->id}/tags", ['tags' => [$tag->id]])
        ->assertOk()
        ->assertJsonPath('contact.tags.0.id', $tag->id)
        ->assertJsonPath('contact.tags.0.name', 'VIP');

    expect($contact->fresh()->tags)->toHaveCount(1);
});

test('the endpoint is a sync, so sending a shorter set removes the rest', function () {
    Event::fake();
    $user = contactTagsUser();
    $contact = contactTagsContact($user);
    $vip = contactTag($user, 'VIP');
    $reseller = contactTag($user, 'Revenda');

    $contact->tags()->sync([$vip->id, $reseller->id]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/contacts/{$contact->id}/tags", ['tags' => [$vip->id]])
        ->assertOk();

    expect($contact->fresh()->tags->pluck('id')->all())->toBe([$vip->id]);
});

test('sending an empty set clears the tags', function () {
    Event::fake();
    $user = contactTagsUser();
    $contact = contactTagsContact($user);
    $contact->tags()->sync([contactTag($user)->id]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/contacts/{$contact->id}/tags", ['tags' => []])
        ->assertOk();

    expect($contact->fresh()->tags)->toBeEmpty();
});

test('a tag belonging to another tenant is dropped, not applied', function () {
    Event::fake();
    $user = contactTagsUser();
    $contact = contactTagsContact($user);
    $foreign = contactTag(contactTagsUser(), 'Outro workspace');

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/contacts/{$contact->id}/tags", ['tags' => [$foreign->id]])
        ->assertOk();

    expect($contact->fresh()->tags)->toBeEmpty();
});

test('a contact from another tenant cannot be tagged', function () {
    Event::fake();
    $user = contactTagsUser();
    $stranger = contactTagsContact(contactTagsUser(), '5511888888888');

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/contacts/{$stranger->id}/tags", ['tags' => []])
        ->assertNotFound();
});

/**
 * The point of the feature: a tag applied while one thread was open is still
 * there on the thread opened after it was resolved. Conversation tags are not
 * — that is the gap this exists to close, and the assertion below says so in
 * both directions so a future change cannot quietly merge the two.
 */
test('contact tags survive into the next conversation, conversation tags do not', function () {
    Event::fake();
    $user = contactTagsUser();
    $connection = contactTagsConnection($user);
    $contact = contactTagsContact($user);
    $tag = contactTag($user);

    $first = contactTagsConversation($connection, $contact);
    $first->tags()->sync([$tag->id]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/contacts/{$contact->id}/tags", ['tags' => [$tag->id]])
        ->assertOk();

    $first->update(['status' => ConversationStatus::Resolved]);
    $second = contactTagsConversation($connection, $contact);
    // The sync page skips threads with no message (an opened widget session
    // that never typed), so the fixture has to look like a real one.
    $second->messages()->create([
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Oi de novo',
        'sent_at' => now(),
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/conversations?limit=100');

    $row = collect($response->json('data'))->firstWhere('id', $second->id);

    expect($row)->not->toBeNull()
        ->and($row['tags'])->toBeEmpty()
        ->and(collect($row['contact']['tags'])->pluck('id')->all())->toBe([$tag->id]);
});

test('tagging a contact broadcasts, so open inboxes update without a resync', function () {
    Event::fake();
    $user = contactTagsUser();
    $contact = contactTagsContact($user);
    $tag = contactTag($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/contacts/{$contact->id}/tags", ['tags' => [$tag->id]])
        ->assertOk();

    Event::assertDispatched(ContactTagsUpdated::class, function ($event) use ($contact, $tag) {
        $payload = $event->broadcastWith();

        return $payload['contact_id'] === (string) $contact->id
            && collect($payload['tags'])->pluck('id')->all() === [$tag->id];
    });
});

/**
 * The half that reaches the tab that was closed. A contact tag changes no row
 * in `conversations`, and the client syncs conversations by `updated_at` — so
 * without the bump an agent who was offline keeps the old tags forever.
 */
test('tagging a contact bumps its conversations so the delta sync carries it', function () {
    Event::fake();
    $user = contactTagsUser();
    $connection = contactTagsConnection($user);
    $contact = contactTagsContact($user);
    $conversation = contactTagsConversation($connection, $contact);

    $conversation->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    $before = $conversation->fresh()->updated_at;

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/contacts/{$contact->id}/tags", ['tags' => [contactTag($user)->id]])
        ->assertOk();

    expect($conversation->fresh()->updated_at->greaterThan($before))->toBeTrue();
});

test('the contact book can be filtered by tag', function () {
    Event::fake();
    $user = contactTagsUser();
    $tagged = contactTagsContact($user, '5511111111111');
    contactTagsContact($user, '5522222222222');
    $tag = contactTag($user);

    $tagged->tags()->sync([$tag->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/contacts?tags[]={$tag->id}")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$tagged->id]);
});

test('deleting a tag takes it off the contacts carrying it', function () {
    Event::fake();
    $user = contactTagsUser();
    $contact = contactTagsContact($user);
    $tag = contactTag($user);
    $contact->tags()->sync([$tag->id]);

    $tag->delete();

    expect($contact->fresh()->tags)->toBeEmpty();
});

/**
 * A tagging node written before this feature has no `target` key at all.
 * Reading that absence as "the contact" would have flows silently start
 * writing permanent labels on customers because the engine was upgraded.
 */
test('a tagging node without a target still tags the conversation', function () {
    Event::fake();
    $user = contactTagsUser();
    $connection = contactTagsConnection($user);
    $contact = contactTagsContact($user);
    $conversation = contactTagsConversation($connection, $contact, ConversationStatus::Pending);
    $tag = contactTag($user);

    runTaggingNode($user, $conversation, ['action' => 'add', 'tags' => [$tag->id]]);

    expect($conversation->fresh()->tags->pluck('id')->all())->toBe([$tag->id])
        ->and($contact->fresh()->tags)->toBeEmpty();
});

test('a tagging node targeting the contact writes there instead', function () {
    Event::fake();
    $user = contactTagsUser();
    $connection = contactTagsConnection($user);
    $contact = contactTagsContact($user);
    $conversation = contactTagsConversation($connection, $contact, ConversationStatus::Pending);
    $tag = contactTag($user);

    runTaggingNode($user, $conversation, ['action' => 'add', 'target' => 'contact', 'tags' => [$tag->id]]);

    expect($contact->fresh()->tags->pluck('id')->all())->toBe([$tag->id])
        ->and($conversation->fresh()->tags)->toBeEmpty();
});

test('a tagging node can remove a contact tag', function () {
    Event::fake();
    $user = contactTagsUser();
    $connection = contactTagsConnection($user);
    $contact = contactTagsContact($user);
    $conversation = contactTagsConversation($connection, $contact, ConversationStatus::Pending);
    $tag = contactTag($user);
    $contact->tags()->sync([$tag->id]);

    runTaggingNode($user, $conversation, ['action' => 'remove', 'target' => 'contact', 'tags' => [$tag->id]]);

    expect($contact->fresh()->tags)->toBeEmpty();
});

/**
 * Run a `start → tagging` flow over this conversation.
 *
 * Driven through startFlow rather than by poking the executor's internals, so
 * the node is reached the same way a real message reaches it.
 */
function runTaggingNode(User $user, Conversation $conversation, array $data): void
{
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Tagging']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $tagging = $flow->nodes()->create([
        'type' => NodeType::Tagging,
        'data' => $data,
        'position_x' => 100,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $tagging->id, 'condition_value' => null]);

    $conversation->connection->update(['flow_id' => $flow->id]);

    (new FlowExecutor)->startFlow($conversation->fresh());
}
