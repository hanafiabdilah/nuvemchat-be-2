<?php

use App\Enums\Connection\Channel;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\ConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function actingAsTenantUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->setRelation('tenant', $tenant);

    Sanctum::actingAs($user);

    return $user;
}

function validEmailConnectPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'inbox@example.com',
        'password' => 'secret-password',
        'imap_host' => 'imap.example.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl',
    ], $overrides);
}

test('it creates an email connection record', function () {
    $this->withoutMiddleware();
    actingAsTenantUser();

    $response = $this->postJson('/api/connections', [
        'channel' => 'email',
        'name' => 'Suporte',
        'color' => '#336699',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.channel', 'email')
        ->assertJsonPath('data.name', 'Suporte');

    $this->assertDatabaseHas('connections', [
        'channel' => 'email',
        'name' => 'Suporte',
    ]);
});

test('it validates the email connect payload before connecting', function () {
    $this->withoutMiddleware();
    $user = actingAsTenantUser();

    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::Email,
        'name' => 'Suporte',
    ]);

    $response = $this->postJson("/api/connections/{$connection->id}/connect", [
        'email' => 'invalid-email',
        'imap_port' => 70000,
        'imap_encryption' => 'plain',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption']);
});

test('it never forwards provider authentication failures as http 401', function () {
    $this->withoutMiddleware();
    Event::fake();

    $user = actingAsTenantUser();

    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::Email,
        'name' => 'Suporte',
    ]);

    $service = Mockery::mock(ConnectionService::class);
    $service->shouldReceive('connect')
        ->once()
        ->with(Mockery::type(Connection::class), Mockery::type('array'))
        ->andThrow(new ConnectionException('Falha na autenticacao IMAP: usuario ou senha invalidos.', 401));

    app()->instance(ConnectionService::class, $service);

    $response = $this->postJson("/api/connections/{$connection->id}/connect", validEmailConnectPayload());

    $response->assertStatus(502)
        ->assertJsonPath('message', 'Nao foi possivel conectar a instancia junto ao provedor. Verifique a configuracao da integracao.');
});
