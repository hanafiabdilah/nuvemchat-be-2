<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function threadTestUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function threadTestConnection(User $user, bool $grant = true): Connection
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    if ($grant) {
        $user->connections()->syncWithoutDetaching([$connection->id]);
    }

    return $connection;
}

function threadTestConversation(Connection $connection, int $messageCount = 0): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => (string) fake()->unique()->numerify('55119########'),
        'name' => 'Ana',
        'channel' => $connection->channel,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Resolved,
    ]);

    for ($i = 1; $i <= $messageCount; $i++) {
        $conversation->messages()->create([
            'external_id' => 'wamid.'.uniqid(),
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => 'mensagem '.$i,
            'sent_at' => now(),
        ]);
    }

    return $conversation;
}

it('returns the newest page first and says there is more above', function () {
    $user = threadTestUser();
    $conversation = threadTestConversation(threadTestConnection($user), 5);

    $response = $this->actingAs($user)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=2");

    $response->assertOk();
    expect($response->json('data.*.body'))->toBe(['mensagem 5', 'mensagem 4']);
    expect($response->json('has_more'))->toBeTrue();
    expect($response->json('next_before'))->toBe($response->json('data.1.id'));
});

it('walks backwards with the cursor until the thread runs out', function () {
    $user = threadTestUser();
    $conversation = threadTestConversation(threadTestConnection($user), 3);

    $first = $this->actingAs($user)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=2");

    $second = $this->actingAs($user)->getJson(
        "/api/conversations/{$conversation->id}/messages?limit=2&before=".$first->json('next_before')
    );

    expect($second->json('data.*.body'))->toBe(['mensagem 1']);
    expect($second->json('has_more'))->toBeFalse();
    expect($second->json('next_before'))->toBeNull();
});

it('reports no more when the whole thread fits in one page', function () {
    $user = threadTestUser();
    $conversation = threadTestConversation(threadTestConnection($user), 2);

    $response = $this->actingAs($user)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=50");

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('has_more'))->toBeFalse();
});

it('is gated on connection access, not on tenancy', function () {
    $user = threadTestUser();
    // Same tenant, but this agent was never given the connection.
    $conversation = threadTestConversation(threadTestConnection($user, grant: false), 1);

    $this->actingAs($user)
        ->getJson("/api/conversations/{$conversation->id}/messages")
        ->assertNotFound();
});

it('lets an agent read a thread assigned to nobody', function () {
    // Reading is gated on visibleTo, not isAccessibleBy: the threads most
    // worth opening are the unassigned ones sitting in the queue.
    $user = threadTestUser();
    $conversation = threadTestConversation(threadTestConnection($user), 1);
    $conversation->update(['user_id' => null, 'status' => ConversationStatus::Pending]);

    // Not a count: moving the status writes its own note into the thread (see
    // ConversationObserver), and that note is part of the history too.
    $response = $this->actingAs($user)->getJson("/api/conversations/{$conversation->id}/messages");

    $response->assertOk();
    expect($response->json('data.*.body'))->toContain('mensagem 1');
});
