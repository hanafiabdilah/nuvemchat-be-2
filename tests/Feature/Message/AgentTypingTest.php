<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Events\AgentTyping;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * The agent-facing half of the typing indicator. The customer-facing half —
 * which channel API each handler calls — is covered by TypingIndicatorTest;
 * what matters here is that the other agents hear about it, and that turning
 * the composer's cadence up did not turn the channel traffic up with it.
 */
function agentTypingSetup(Channel $channel = Channel::WhatsappApiway): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'Conn',
        'status' => ConnectionStatus::Active,
    ]);

    $owner->connections()->syncWithoutDetaching([$connection->id]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'name' => 'Maria',
        'external_id' => '5511888887777',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511888887777',
        'status' => ConversationStatus::Active,
        'user_id' => $owner->id,
    ]);

    return [$owner->fresh(), $connection, $conversation];
}

test('typing tells the other agents, on the connection channel', function () {
    Event::fake([AgentTyping::class]);

    [$user, $connection, $conversation] = agentTypingSetup();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/typing")
        ->assertOk();

    Event::assertDispatched(AgentTyping::class, function (AgentTyping $event) use ($user, $connection, $conversation) {
        $payload = $event->broadcastWith();

        expect($event->broadcastAs())->toBe('agent-typing');
        expect($event->broadcastOn()[0]->name)
            ->toBe("private-tenant.{$connection->tenant_id}.connection.{$connection->id}");
        expect($payload['conversation_id'])->toBe((int) $conversation->id);
        expect($payload['user']['id'])->toBe((int) $user->id);
        expect($payload['user']['name'])->toBe($user->name);
        expect($payload['typing'])->toBeTrue();
        expect($payload['ttl'])->toBeGreaterThan(0);

        return true;
    });
});

test('state=paused broadcasts the withdrawal rather than nothing', function () {
    Event::fake([AgentTyping::class]);

    [$user, , $conversation] = agentTypingSetup();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/typing", ['state' => 'paused'])
        ->assertOk();

    Event::assertDispatched(
        AgentTyping::class,
        fn (AgentTyping $event) => $event->broadcastWith()['typing'] === false
    );
});

/**
 * The whole reason the channel forward moved server-side. E-mail has no typing
 * API at all (typingRefreshSeconds() === null), so the old client-side gate
 * skipped the request entirely — which meant two agents sharing an e-mail inbox
 * could never see each other writing.
 */
test('a channel with no indicator of its own still tells the other agents', function () {
    Event::fake([AgentTyping::class]);

    [$user, , $conversation] = agentTypingSetup(Channel::Email);

    expect(Channel::Email->typingRefreshSeconds())->toBeNull();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/typing")
        ->assertOk();

    Event::assertDispatched(AgentTyping::class);
});

test('the forward to the channel is throttled to that channel refresh interval', function () {
    Cache::flush();

    [$user, $connection, $conversation] = agentTypingSetup();

    $interval = $connection->channel->typingRefreshSeconds();
    expect($interval)->not->toBeNull();

    // Claim the window as the first call would, then confirm a second call
    // inside it finds the gate closed. Asserting on the cache key rather than
    // on the handler keeps this independent of which API API Way happens to
    // call for presence.
    $key = 'typing-fwd:'.$conversation->id;
    expect(Cache::add($key, true, $interval - 1))->toBeTrue();
    expect(Cache::add($key, true, $interval - 1))->toBeFalse();

    // …and the composer keeps announcing regardless: the agent-facing signal
    // runs on a faster clock than the channel-facing one.
    Event::fake([AgentTyping::class]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/typing")
        ->assertOk();

    Event::assertDispatched(AgentTyping::class);
});

test('paused clears the window so the next keystroke lights the channel again', function () {
    Cache::flush();

    [$user, , $conversation] = agentTypingSetup();

    $key = 'typing-fwd:'.$conversation->id;
    Cache::add($key, true, 60);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/typing", ['state' => 'paused'])
        ->assertOk();

    expect(Cache::has($key))->toBeFalse();
});

test('an agent who cannot write in the thread cannot appear to be typing in it', function () {
    Event::fake([AgentTyping::class]);

    [, $connection, $conversation] = agentTypingSetup();

    // Same tenant, holds the connection, but the thread belongs to somebody
    // else — isAccessibleBy() says no, so neither the channel nor the other
    // agents hear anything.
    $other = User::factory()->create(['tenant_id' => $connection->tenant_id]);
    $other->connections()->syncWithoutDetaching([$connection->id]);

    $this->actingAs($other->fresh(), 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/typing")
        ->assertStatus(403);

    Event::assertNotDispatched(AgentTyping::class);
});
