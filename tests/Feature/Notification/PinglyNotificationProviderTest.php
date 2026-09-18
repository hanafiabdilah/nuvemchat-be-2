<?php

use App\Jobs\SendWhatsappMessageJob;
use App\Models\Admin;
use App\Models\Setting;
use App\Models\WhatsappMessageLog;
use App\Services\Notification\NotificationConfig;
use App\Services\Notification\NotificationProviderFactory;
use App\Services\Notification\Providers\PinglyNotificationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The platform sends its own WhatsApp notifications through its own public API.
 * That API now authenticates the WORKSPACE, not a connection — so the number a
 * notification goes out from is named per request and the pair is meaningless
 * apart: a key without a connection id cannot send anything.
 */
function pinglyConfigured(): void
{
    Setting::set(NotificationConfig::KEY_PROVIDER, 'pingly');
    Setting::set(NotificationConfig::KEY_PINGLY_BASE_URL, 'https://chat.pingly.com.br/api/v1');
    Setting::set(NotificationConfig::KEY_PINGLY_API_KEY, 'pk_live_key');
    Setting::set(NotificationConfig::KEY_PINGLY_CONNECTION_ID, 'conn_abc123def456');
}

test('the provider needs the workspace key and the connection it sends through', function () {
    $provider = app(PinglyNotificationProvider::class);

    Setting::set(NotificationConfig::KEY_PINGLY_API_KEY, 'pk_live_key');
    expect($provider->isConfigured())->toBeFalse();

    Setting::set(NotificationConfig::KEY_PINGLY_CONNECTION_ID, 'conn_abc123def456');
    expect($provider->isConfigured())->toBeTrue();
});

test('it authenticates with the workspace key and names the connection in the body', function () {
    pinglyConfigured();
    Http::fake(['*' => Http::response(['message' => 'Message sent successfully'], 201)]);

    app(PinglyNotificationProvider::class)->send('5511999998888', 'Seu código é 424242');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://chat.pingly.com.br/api/v1/send-message'
            && $request->header('X-Api-Key') === ['pk_live_key']
            && $request['connection_id'] === 'conn_abc123def456'
            && $request['message'] === 'Seu código é 424242'
            // The recipient under both spellings: the public API calls it
            // `phone` on API Way and `to` on WhatsApp Official, and each handler
            // ignores the other's field. One body serves either connection.
            && $request['phone'] === '5511999998888'
            && $request['to'] === '5511999998888';
    });
});

test('a connection the API refuses is logged as failed rather than swallowed', function () {
    pinglyConfigured();
    Http::fake(['*' => Http::response(['message' => 'Conexão não encontrada nesta conta.'], 422)]);

    // $tries = 2: the job rethrows so the queue can retry, and on `sync` that
    // reaches the caller.
    expect(fn () => (new SendWhatsappMessageJob('5511999998888', 'oi', 'otp'))
        ->handle(app(NotificationProviderFactory::class)))
        ->toThrow(RuntimeException::class);

    $log = WhatsappMessageLog::query()->latest('id')->first();
    expect($log->provider)->toBe('pingly');
    expect($log->status)->toBe(WhatsappMessageLog::STATUS_FAILED);
});

test('a half-configured provider never sends and says so on the log', function () {
    Setting::set(NotificationConfig::KEY_PROVIDER, 'pingly');
    Setting::set(NotificationConfig::KEY_PINGLY_API_KEY, 'pk_live_key');
    Http::fake();

    (new SendWhatsappMessageJob('5511999998888', 'oi', 'otp'))->handle(app(NotificationProviderFactory::class));

    Http::assertNothingSent();
    expect(WhatsappMessageLog::query()->latest('id')->first()->error)->toBe('provider not configured');
});

// --- W-API is gone --------------------------------------------------------

test('the w-api provider is no longer registered', function () {
    $factory = app(NotificationProviderFactory::class);

    expect($factory->available())->toBe(['pingly', 'proxybr']);
    expect(fn () => $factory->make('wapi'))->toThrow(InvalidArgumentException::class);
});

test('the Back Office cannot save a provider that no longer exists', function () {
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.settings.manage', 'web'));
    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    // Refused at the door: an unknown key is otherwise only discovered when the
    // factory throws inside the send job — which is to say, when a customer does
    // not get their verification code.
    $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
        'notifications' => ['provider' => 'wapi'],
    ])->assertStatus(422)->assertJsonValidationErrors('notifications.provider');

    $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
        'notifications' => ['provider' => 'pingly', 'pingly' => ['connection_id' => 'conn_xyz']],
    ])->assertOk()->assertJsonPath('data.notifications.pingly.connection_id', 'conn_xyz');
});
