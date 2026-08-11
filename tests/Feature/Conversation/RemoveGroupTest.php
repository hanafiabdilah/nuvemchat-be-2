<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\GroupRemoved;
use App\Jobs\SyncContactPhoto;
use App\Jobs\SyncGroupMetadata;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\TelegramHandler;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const RG_TG_GROUP_ID = -100555444333;
const RG_WA_GROUP_JID = '120363419920035031@g.us';

beforeEach(function () {
    Http::fake(); // photo / metadata lookups stay offline
});

function removeGroupUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function removeGroupConnection(User $user, Channel $channel): Connection
{
    return Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => $channel,
        'name' => $channel->value,
        'status' => ConnectionStatus::Active,
        'credentials' => $channel === Channel::Telegram
            ? ['token' => 'test-token']
            : ['instance_id' => 'INST-1', 'token' => 'test-token'],
    ]);
}

function telegramRemoveGroupPayload(array $chatOverrides = [], int $messageId = 100): array
{
    return [
        'update_id' => 900000010,
        'message' => [
            'message_id' => $messageId,
            'from' => ['id' => 501001, 'is_bot' => false, 'first_name' => 'Alice', 'username' => 'alice'],
            'chat' => array_merge(['id' => RG_TG_GROUP_ID, 'title' => 'Time de Suporte', 'type' => 'supergroup'], $chatOverrides),
            'date' => 1754500000,
            'text' => 'Olá do grupo!',
        ],
    ];
}

function apiwayRemoveGroupPayload(string $messageId = 'MSG-1'): array
{
    return [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => $messageId,
                'Chat' => RG_WA_GROUP_JID,
                'Sender' => '45148847243518@lid',
                'SenderAlt' => '555491094949:12@s.whatsapp.net',
                'PushName' => 'Ana',
                'IsFromMe' => false,
                'IsGroup' => true,
                'Timestamp' => '2026-08-11T10:00:00Z',
                'Type' => 'text',
            ],
            'Message' => ['conversation' => 'Oi pessoal'],
        ],
    ];
}

test('removing a group flags it and tells open panels to drop its threads', function () {
    Event::fake();
    $user = removeGroupUser();
    $connection = removeGroupConnection($user, Channel::Telegram);

    (new TelegramHandler)->handle($connection, telegramRemoveGroupPayload());
    $group = Contact::where('is_group', true)->firstOrFail();
    $conversationId = Conversation::firstOrFail()->id;

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/groups/{$group->id}/remove")
        ->assertOk();

    expect($group->fresh()->isRemovedGroup())->toBeTrue();

    Event::assertDispatched(GroupRemoved::class, fn ($event) => $event->contactId === $group->id
        && in_array($conversationId, $event->conversationIds, true));
});

test('a telegram message from a removed group never reaches the inbox, but still renames it', function () {
    Event::fake();
    $user = removeGroupUser();
    $connection = removeGroupConnection($user, Channel::Telegram);

    (new TelegramHandler)->handle($connection, telegramRemoveGroupPayload());
    $group = Contact::where('is_group', true)->firstOrFail();
    $group->update(['group_removed_at' => now()]);

    $messagesBefore = Message::count();

    // Same group, new message, and the title changed in the meantime.
    (new TelegramHandler)->handle($connection, telegramRemoveGroupPayload(['title' => 'Suporte Nível 2'], 101));

    expect(Message::count())->toBe($messagesBefore)
        // Identity keeps flowing: only the messages are dropped.
        ->and($group->fresh()->name)->toBe('Suporte Nível 2');
});

test('an api way message from a removed group is dropped while name and photo keep syncing', function () {
    Event::fake();
    Queue::fake();
    $user = removeGroupUser();
    $connection = removeGroupConnection($user, Channel::WhatsappApiway);

    // Pre-flag the group before its first message ever arrives.
    $group = Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => RG_WA_GROUP_JID,
        'name' => '120363419920035031',
        'channel' => Channel::WhatsappApiway,
        'is_group' => true,
        'group_removed_at' => now(),
    ]);

    (new WhatsappApiwayHandler)->handle($connection, apiwayRemoveGroupPayload());

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);

    // The two lookups that keep a removed group correctly labelled still run.
    Queue::assertPushed(SyncGroupMetadata::class, fn ($job) => $job->contact->id === $group->id);
    Queue::assertPushed(SyncContactPhoto::class, fn ($job) => $job->contact->id === $group->id);
});

test('a removed group disappears from the conversation list and comes back on restore', function () {
    Event::fake();
    $user = removeGroupUser();
    $connection = removeGroupConnection($user, Channel::Telegram);

    (new TelegramHandler)->handle($connection, telegramRemoveGroupPayload());
    $group = Contact::where('is_group', true)->firstOrFail();

    $this->actingAs($user, 'sanctum')->getJson('/api/conversations')->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($user, 'sanctum')->postJson("/api/groups/{$group->id}/remove")->assertOk();
    $this->actingAs($user, 'sanctum')->getJson('/api/conversations')->assertOk()->assertJsonCount(0, 'data');

    // Nothing was deleted — the thread and its messages are still on disk.
    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(1);

    $this->actingAs($user, 'sanctum')->deleteJson("/api/groups/{$group->id}/remove")->assertOk();

    expect($group->fresh()->isRemovedGroup())->toBeFalse();
    $this->actingAs($user, 'sanctum')->getJson('/api/conversations')->assertOk()->assertJsonCount(1, 'data');
});

test('a restored group receives messages again', function () {
    Event::fake();
    $user = removeGroupUser();
    $connection = removeGroupConnection($user, Channel::Telegram);

    (new TelegramHandler)->handle($connection, telegramRemoveGroupPayload());
    $group = Contact::where('is_group', true)->firstOrFail();

    $this->actingAs($user, 'sanctum')->postJson("/api/groups/{$group->id}/remove")->assertOk();
    (new TelegramHandler)->handle($connection, telegramRemoveGroupPayload([], 101));
    expect(Message::count())->toBe(1);

    $this->actingAs($user, 'sanctum')->deleteJson("/api/groups/{$group->id}/remove")->assertOk();
    (new TelegramHandler)->handle($connection, telegramRemoveGroupPayload([], 102));

    expect(Message::count())->toBe(2);
});

test('the removed list only shows this tenant groups', function () {
    Event::fake();
    $user = removeGroupUser();
    $connection = removeGroupConnection($user, Channel::Telegram);
    (new TelegramHandler)->handle($connection, telegramRemoveGroupPayload());
    $group = Contact::where('is_group', true)->firstOrFail();
    $this->actingAs($user, 'sanctum')->postJson("/api/groups/{$group->id}/remove")->assertOk();

    $stranger = removeGroupUser();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/groups/removed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $group->id);

    $this->actingAs($stranger, 'sanctum')
        ->getJson('/api/groups/removed')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // And a stranger cannot remove someone else's group.
    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/groups/{$group->id}/remove")
        ->assertNotFound();
});

test('a private contact can never be removed as a group', function () {
    Event::fake();
    $user = removeGroupUser();
    $connection = removeGroupConnection($user, Channel::Telegram);

    $person = Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => '501001',
        'name' => 'Alice',
        'channel' => $connection->channel,
        'is_group' => false,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/groups/{$person->id}/remove")
        ->assertNotFound();

    expect($person->fresh()->group_removed_at)->toBeNull();
});

test('messages already stored stay untouched while removed', function () {
    Event::fake();
    $user = removeGroupUser();
    $connection = removeGroupConnection($user, Channel::Telegram);

    (new TelegramHandler)->handle($connection, telegramRemoveGroupPayload());
    $group = Contact::where('is_group', true)->firstOrFail();

    $this->actingAs($user, 'sanctum')->postJson("/api/groups/{$group->id}/remove")->assertOk();

    expect(Message::where('sender_type', SenderType::Incoming)->where('message_type', MessageType::Text)->count())->toBe(1);
});
