<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Channels\DiscordChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function discordTestTenant(): Tenant
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant;
}

function discordConnectionRow(Tenant $tenant, array $attributes = []): Connection
{
    return Connection::create(array_merge([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Discord,
        'name' => 'Discord ' . uniqid(),
        'color' => '#5865F2',
        'status' => Status::Inactive,
        'credentials' => null,
    ], $attributes));
}

test('connect validates the bot token and stores the bot identity', function () {
    Http::fake([
        'discord.com/api/v10/users/@me' => Http::response([
            'id' => '111222333444555666',
            'username' => 'pingly-bot',
            'global_name' => 'Pingly Support',
        ]),
        'discord.com/api/v10/applications/@me' => Http::response([
            'id' => '999888777666555444',
        ]),
    ]);

    $connection = discordConnectionRow(discordTestTenant());

    (new DiscordChannel)->connect($connection, ['token' => 'bot-token-abc']);

    $connection->refresh();

    expect($connection->status)->toBe(Status::Active)
        ->and($connection->credentials['token'])->toBe('bot-token-abc')
        ->and($connection->credentials['bot_user_id'])->toBe('111222333444555666')
        ->and($connection->credentials['username'])->toBe('pingly-bot')
        ->and($connection->credentials['application_id'])->toBe('999888777666555444');
});

test('an invalid token is rejected with a validation error', function () {
    Http::fake([
        'discord.com/api/v10/users/@me' => Http::response(['message' => '401: Unauthorized'], 401),
    ]);

    $connection = discordConnectionRow(discordTestTenant());

    expect(fn () => (new DiscordChannel)->connect($connection, ['token' => 'bad-token']))
        ->toThrow(ValidationException::class);
});

test('the same bot cannot be connected twice', function () {
    Http::fake([
        'discord.com/api/v10/users/@me' => Http::response([
            'id' => '111222333444555666',
            'username' => 'pingly-bot',
        ]),
        'discord.com/api/v10/applications/@me' => Http::response(['id' => '111']),
    ]);

    $tenant = discordTestTenant();

    discordConnectionRow($tenant, [
        'status' => Status::Active,
        'credentials' => ['token' => 'other', 'bot_user_id' => '111222333444555666'],
    ]);

    $connection = discordConnectionRow($tenant);

    expect(fn () => (new DiscordChannel)->connect($connection, ['token' => 'bot-token-abc']))
        ->toThrow(ValidationException::class);
});

test('check status deactivates the connection when the token died', function () {
    Http::fake([
        'discord.com/api/v10/users/@me' => Http::response(['message' => '401: Unauthorized'], 401),
    ]);

    $connection = discordConnectionRow(discordTestTenant(), [
        'status' => Status::Active,
        'credentials' => ['token' => 'revoked', 'bot_user_id' => '1'],
    ]);

    expect(fn () => (new DiscordChannel)->checkStatus($connection))
        ->toThrow(\App\Exceptions\ConnectionException::class);

    expect($connection->fresh()->status)->toBe(Status::Inactive);
});

test('disconnect is local-only and clears credentials', function () {
    Http::fake();

    $connection = discordConnectionRow(discordTestTenant(), [
        'status' => Status::Active,
        'credentials' => ['token' => 'bot-token-abc', 'bot_user_id' => '1'],
    ]);

    (new DiscordChannel)->disconnect($connection);

    $connection->refresh();

    expect($connection->status)->toBe(Status::Inactive)
        ->and($connection->credentials)->toBeNull();

    Http::assertNothingSent();
});
