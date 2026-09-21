<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Http\Resources\ConnectionResource;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\ChatService;
use App\Services\Webhook\ChatWebhookSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * /webhook/chat/{id} is the inbound path for Telegram and API Way, and it used
 * to accept anything. These cover the two ways the secret can travel, the two
 * ways a delivery is refused, and the one case that is still let through on
 * purpose.
 */

const CHAT_HOOK_SECRET = 'aaaaBBBBccccDDDDeeeeFFFFggggHHHHiiiiJJJJkkkkLLLL';

function chatHookConnection(Channel $channel = Channel::Telegram, ?string $secret = CHAT_HOOK_SECRET): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    $credentials = ['token' => 'bot-token', 'username' => 'loja_bot'];

    if ($secret !== null) {
        $credentials[ChatWebhookSecret::CREDENTIAL_KEY] = $secret;
    }

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'Inbox',
        'status' => ConnectionStatus::Active,
        'credentials' => $credentials,
    ]);
}

/**
 * The handler chain is not what is under test here, and running it would drag
 * in every channel's parsing. The spy also proves the difference that matters:
 * a refused delivery must not reach it at all.
 */
class CountingChatService extends ChatService
{
    public int $calls = 0;

    public function handle(Connection $connection, array $payload)
    {
        $this->calls++;
    }
}

function spyOnChatService(): CountingChatService
{
    $spy = new CountingChatService();

    app()->instance(ChatService::class, $spy);

    return $spy;
}

it('rejects a delivery with no credential once the connection has a secret', function () {
    $spy = spyOnChatService();
    $connection = chatHookConnection();

    $this->postJson("/webhook/chat/{$connection->id}", ['message' => ['text' => 'oi']])
        ->assertStatus(401);

    expect($spy->calls)->toBe(0);
});

it('rejects a wrong secret', function () {
    $spy = spyOnChatService();
    $connection = chatHookConnection();

    $this->withHeader(ChatWebhookSecret::TELEGRAM_HEADER, 'not-the-secret')
        ->postJson("/webhook/chat/{$connection->id}", ['message' => ['text' => 'oi']])
        ->assertStatus(401);

    $this->postJson("/webhook/chat/{$connection->id}/nottherightoneeither", ['message' => ['text' => 'oi']])
        ->assertStatus(401);

    expect($spy->calls)->toBe(0);
});

it('accepts Telegram’s secret_token header', function () {
    $spy = spyOnChatService();
    $connection = chatHookConnection();

    $this->withHeader(ChatWebhookSecret::TELEGRAM_HEADER, CHAT_HOOK_SECRET)
        ->postJson("/webhook/chat/{$connection->id}", ['message' => ['text' => 'oi']])
        ->assertOk();

    expect($spy->calls)->toBe(1);
});

it('accepts the secret as the last path segment, which is all the API Way core can carry', function () {
    $spy = spyOnChatService();
    $connection = chatHookConnection(Channel::WhatsappApiway);

    $this->postJson("/webhook/chat/{$connection->id}/".CHAT_HOOK_SECRET, ['type' => 'Message'])
        ->assertOk();

    expect($spy->calls)->toBe(1);
});

it('still serves a connection that has not been secured yet, so deploying this does not drop live traffic', function () {
    $spy = spyOnChatService();
    $connection = chatHookConnection(Channel::Telegram, secret: null);

    $this->postJson("/webhook/chat/{$connection->id}", ['message' => ['text' => 'oi']])
        ->assertOk();

    expect($spy->calls)->toBe(1);
});

it('refuses an unsecured connection once strict mode is on', function () {
    config()->set('services.webhooks.chat_strict', true);

    $spy = spyOnChatService();
    $connection = chatHookConnection(Channel::Telegram, secret: null);

    $this->postJson("/webhook/chat/{$connection->id}", ['message' => ['text' => 'oi']])
        ->assertStatus(401);

    expect($spy->calls)->toBe(0);
});

it('answers 404 for a connection that does not exist, without revealing anything else', function () {
    spyOnChatService();

    $this->postJson('/webhook/chat/999999', ['message' => ['text' => 'oi']])
        ->assertStatus(404);
});

it('keeps the webhook secret out of whatever the dashboard is served', function () {
    // Straight at the resource rather than through GET /api/connections: the
    // route drags in roles, the subscription gate and WhatsApp verification,
    // none of which is what this is asserting. What matters is that the secret
    // cannot leave through the one place connection credentials are serialised.
    $connection = chatHookConnection();

    $payload = (new ConnectionResource($connection))->toArray(request());

    expect($connection->credentials)->toHaveKey(ChatWebhookSecret::CREDENTIAL_KEY)
        ->and($payload['credentials'])->not->toHaveKey(ChatWebhookSecret::CREDENTIAL_KEY)
        // The identity still goes out — the scrub is by key name and must not
        // take the whole block with it.
        ->and($payload['credentials'])->toHaveKey('username')
        // And the bot token does not, for a caller with no permission to
        // connect. See ConnectionCredentialLeakTest.
        ->and($payload['credentials'])->not->toHaveKey('token');
});
