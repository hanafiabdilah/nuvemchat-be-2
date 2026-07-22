<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Channels\EmailChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function credentialsTenantUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->setRelation('tenant', $tenant);

    Sanctum::actingAs($user);

    return $user;
}

/** A connected mailbox with credentials already on file. */
function connectedMailbox(User $user, array $overrides = []): Connection
{
    return Connection::create(array_merge([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::Email,
        'name' => 'Suporte',
        'status' => Status::Active,
        'credentials' => [
            'email' => 'inbox@example.com',
            'password' => Crypt::encryptString('old-password'),
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
        ],
    ], $overrides));
}

function credentialsPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'inbox@example.com',
        'imap_host' => 'imap.example.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl',
    ], $overrides);
}

/** Stub the channel so no real IMAP/SMTP socket is opened. */
function fakeEmailChannel(): Mockery\MockInterface
{
    $channel = Mockery::mock(EmailChannel::class)->makePartial();
    app()->instance(EmailChannel::class, $channel);

    return $channel;
}

test('it updates the stored credentials of an email connection', function () {
    $this->withoutMiddleware();
    Event::fake();
    $user = credentialsTenantUser();
    $connection = connectedMailbox($user);

    $channel = fakeEmailChannel();
    $channel->shouldReceive('updateCredentials')
        ->once()
        ->with(Mockery::type(Connection::class), Mockery::type('array'));

    $this->putJson("/api/connections/{$connection->id}/credentials", credentialsPayload([
        'imap_host' => 'imap.newhost.com',
        'password' => 'new-password',
    ]))->assertOk()->assertJsonPath('message', 'Credentials updated successfully');
});

test('it rejects credential edits on non-email channels', function () {
    $this->withoutMiddleware();
    $user = credentialsTenantUser();

    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::Telegram,
        'name' => 'Telegram',
        'status' => Status::Active,
    ]);

    $this->putJson("/api/connections/{$connection->id}/credentials", credentialsPayload())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Credentials can only be edited on email connections.');
});

test('it validates the payload', function () {
    $this->withoutMiddleware();
    $user = credentialsTenantUser();
    $connection = connectedMailbox($user);

    $this->putJson("/api/connections/{$connection->id}/credentials", [
        'email' => 'not-an-email',
        'imap_port' => 70000,
        'imap_encryption' => 'plain',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption']);
});

test('the password is optional — omitting it is allowed', function () {
    $this->withoutMiddleware();
    $user = credentialsTenantUser();
    $connection = connectedMailbox($user);

    $channel = fakeEmailChannel();
    $channel->shouldReceive('updateCredentials')->once();

    // No 'password' key at all: the stored one must be reused, not rejected.
    $this->putJson("/api/connections/{$connection->id}/credentials", credentialsPayload())->assertOk();
});

test('a mailbox that rejects the login is never reported as http 401', function () {
    $this->withoutMiddleware();
    Event::fake();
    $user = credentialsTenantUser();
    $connection = connectedMailbox($user);

    $channel = fakeEmailChannel();
    $channel->shouldReceive('updateCredentials')
        ->once()
        ->andThrow(new ConnectionException('Falha na autenticacao IMAP.', 401));

    // 401 would log the SPA out; it must surface as a gateway error instead.
    $this->putJson("/api/connections/{$connection->id}/credentials", credentialsPayload())
        ->assertStatus(502);
});

test('another tenant cannot edit this mailbox', function () {
    $this->withoutMiddleware();
    $owner = credentialsTenantUser();
    $connection = connectedMailbox($owner);

    credentialsTenantUser(); // switch to a different tenant

    $this->putJson("/api/connections/{$connection->id}/credentials", credentialsPayload())
        ->assertNotFound();
});

// --- EmailChannel::updateCredentials ------------------------------------

test('a blank password keeps the stored one', function () {
    $user = credentialsTenantUser();
    $connection = connectedMailbox($user);

    // Partial mock: real updateCredentials, stubbed network assertions.
    $channel = Mockery::mock(EmailChannel::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $channel->shouldReceive('assertImapLogin')->once()->with(Mockery::type('array'), 'old-password');
    $channel->shouldReceive('assertSmtpLogin')->once()->with(Mockery::type('array'), 'old-password');

    $channel->updateCredentials($connection, credentialsPayload([
        'password' => '   ',
        'imap_host' => 'imap.newhost.com',
    ]));

    $connection->refresh();
    expect($connection->credentials['imap_host'])->toBe('imap.newhost.com');
    expect(Crypt::decryptString($connection->credentials['password']))->toBe('old-password');
    expect($connection->status)->toBe(Status::Active);
});

test('a supplied password replaces the stored one', function () {
    $user = credentialsTenantUser();
    $connection = connectedMailbox($user);

    $channel = Mockery::mock(EmailChannel::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $channel->shouldReceive('assertImapLogin')->once()->with(Mockery::type('array'), 'brand-new-password');
    $channel->shouldReceive('assertSmtpLogin')->once()->with(Mockery::type('array'), 'brand-new-password');

    $channel->updateCredentials($connection, credentialsPayload(['password' => 'brand-new-password']));

    expect(Crypt::decryptString($connection->refresh()->credentials['password']))->toBe('brand-new-password');
});

test('a stale mailbox can be repaired even though it is inactive', function () {
    $user = credentialsTenantUser();
    $connection = connectedMailbox($user, ['status' => Status::Inactive]);

    $channel = Mockery::mock(EmailChannel::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $channel->shouldReceive('assertImapLogin')->once();
    $channel->shouldReceive('assertSmtpLogin')->once();

    $channel->updateCredentials($connection, credentialsPayload(['password' => 'fixed-password']));

    expect($connection->refresh()->status)->toBe(Status::Active);
});

test('a failing login leaves the stored credentials untouched', function () {
    $user = credentialsTenantUser();
    $connection = connectedMailbox($user);

    $channel = Mockery::mock(EmailChannel::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $channel->shouldReceive('assertImapLogin')->once()->andThrow(new ConnectionException('nope', 502));
    $channel->shouldNotReceive('assertSmtpLogin');

    expect(fn () => $channel->updateCredentials($connection, credentialsPayload([
        'password' => 'wrong',
        'imap_host' => 'imap.broken.com',
    ])))->toThrow(ConnectionException::class);

    $connection->refresh();
    expect($connection->credentials['imap_host'])->toBe('imap.example.com');
    expect(Crypt::decryptString($connection->credentials['password']))->toBe('old-password');
});

test('a blank password with nothing on file is a validation error', function () {
    $user = credentialsTenantUser();
    $connection = connectedMailbox($user, ['credentials' => null]);

    $channel = Mockery::mock(EmailChannel::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $channel->shouldNotReceive('assertImapLogin');

    expect(fn () => $channel->updateCredentials($connection, credentialsPayload()))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});
