<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Http\Resources\ConnectionResource;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

/**
 * `GET /api/connections` is what the inbox renders its channel rail and filters
 * from, so every signed-in member of the workspace calls it — it cannot be put
 * behind a permission without taking the inbox away from ordinary agents.
 *
 * Which is exactly why what it carries matters. It used to strip secrets per
 * channel and only for four of them, so WhatsApp Official and Instagram handed
 * out an `access_token` — a credential that sends messages as the business,
 * reads the whole WABA, and outlives the agent leaving the company.
 */
uses(RefreshDatabase::class);

function leakConnection(Channel $channel, array $credentials): Connection
{
    $user = User::factory()->create(['email' => 'leak-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'Canal',
        'status' => ConnectionStatus::Active,
        'credentials' => $credentials,
    ]);
}

/** An agent with no permissions at all — the floor this has to hold at. */
function plainAgent(Connection $connection): User
{
    $agent = User::factory()->create(['email' => 'agent-'.uniqid().'@example.test']);
    $agent->forceFill(['tenant_id' => $connection->tenant_id])->save();

    return $agent;
}

it('never ships a channel secret, on any channel', function (Channel $channel, array $credentials, string $secretKey) {
    $connection = leakConnection($channel, $credentials);

    $payload = (new ConnectionResource($connection))->toArray(
        tap(request(), fn ($r) => $r->setUserResolver(fn () => plainAgent($connection)))
    );

    expect($payload['credentials'])->not->toHaveKey($secretKey)
        ->and(json_encode($payload['credentials']))->not->toContain('THE-SECRET');
})->with([
    'whatsapp official' => [
        Channel::WhatsappOfficial,
        ['phone_number_id' => '106540352242922', 'business_account_id' => '111', 'access_token' => 'THE-SECRET'],
        'access_token',
    ],
    'instagram' => [
        Channel::Instagram,
        ['user_id' => '178414', 'username' => 'loja', 'access_token' => 'THE-SECRET'],
        'access_token',
    ],
    'messenger' => [
        Channel::Messenger,
        ['page_id' => '55', 'page_name' => 'Loja', 'access_token' => 'THE-SECRET'],
        'access_token',
    ],
    'tiktok' => [
        Channel::TikTok,
        ['business_id' => '900', 'access_token' => 'THE-SECRET', 'refresh_token' => 'THE-SECRET'],
        'access_token',
    ],
    'email' => [
        Channel::Email,
        ['email' => 'a@b.test', 'imap_host' => 'imap.b.test', 'password' => 'THE-SECRET'],
        'password',
    ],
    'api way' => [
        Channel::WhatsappApiway,
        ['instance_id' => 'uuid-abc', 'phone_number' => '5511999999999', 'token' => 'THE-SECRET'],
        'token',
    ],
]);

it('keeps the identity fields the dashboard actually renders', function () {
    $connection = leakConnection(Channel::WhatsappOfficial, [
        'phone_number_id' => '106540352242922',
        'business_account_id' => '111222333',
        'display_phone_number' => '+55 11 99999-9999',
        'verified_name' => 'Loja Aurora',
        'access_token' => 'THE-SECRET',
    ]);

    $payload = (new ConnectionResource($connection))->toArray(
        tap(request(), fn ($r) => $r->setUserResolver(fn () => plainAgent($connection)))
    );

    // The scrub is by key name, so it must not take the identity with it —
    // this is the block the connection list is built from.
    expect($payload['credentials'])->toHaveKeys([
        'phone_number_id', 'business_account_id', 'display_phone_number', 'verified_name',
    ]);
});

it('gives the bot token only to someone who may connect', function () {
    $connection = leakConnection(Channel::Telegram, ['id' => 1, 'username' => 'bot', 'token' => 'THE-SECRET']);

    // An agent who only answers messages: a bot token is total control of the
    // bot, and nothing they do needs it.
    $agent = plainAgent($connection);
    $forAgent = (new ConnectionResource($connection))->toArray(
        tap(request(), fn ($r) => $r->setUserResolver(fn () => $agent))
    );

    expect($forAgent['credentials'])->not->toHaveKey('token');

    // Somebody who may connect: the wizard shows it in the field it was typed
    // into, so reconnecting does not mean going to find it again.
    Permission::findOrCreate('connections.connect', 'web');
    $agent->givePermissionTo('connections.connect');

    $forConnector = (new ConnectionResource($connection))->toArray(
        tap(request(), fn ($r) => $r->setUserResolver(fn () => $agent->fresh()))
    );

    expect($forConnector['credentials']['token'])->toBe('THE-SECRET');
});
