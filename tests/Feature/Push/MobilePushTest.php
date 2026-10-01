<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\MessageReceived;
use App\Jobs\FanOutPushNotification;
use App\Jobs\SendPushNotification;
use App\Models\Admin;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Push\FirebaseConfig;
use App\Services\Push\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function pushServiceAccountJson(): string
{
    static $json = null;

    if ($json === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        $json = json_encode([
            'type' => 'service_account',
            'project_id' => 'pingly-app',
            'private_key_id' => 'abc123',
            'private_key' => $pem,
            'client_email' => 'push@pingly-app.iam.gserviceaccount.com',
            'client_id' => '1234',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    return $json;
}

function pushWorkspace(): array
{
    Role::findOrCreate('owner', 'web');

    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    $owner->assignRole('owner');

    $agent = User::factory()->create(['tenant_id' => $tenant->id]);
    $outsider = User::factory()->create(['tenant_id' => $tenant->id]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);
    $agent->connections()->sync([$connection->id]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => '5511999999999',
        'name' => 'Ana Souza',
        'channel' => $connection->channel,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Pending,
    ]);

    return [$owner->fresh(), $agent->fresh(), $outsider->fresh(), $conversation];
}

function pushDevice(User $user, string $suffix = '1'): DeviceToken
{
    return DeviceToken::create([
        'tenant_id' => $user->tenant_id,
        'user_id' => $user->id,
        'device_id' => "device-{$user->id}-{$suffix}",
        'token' => "fcm-token-{$user->id}-{$suffix}",
        'platform' => 'android',
        'last_registered_at' => now(),
    ]);
}

/** @return list<int> device token ids that got a send queued */
function pushedDeviceIds(): array
{
    return Queue::pushed(SendPushNotification::class)
        ->map(fn ($job) => $job->deviceTokenId)
        ->sort()->values()->all();
}

test('a phone registers, refreshes in place and is tied to the session', function () {
    [, $agent] = pushWorkspace();
    $token = $agent->createToken('auth_token');

    $this->withToken($token->plainTextToken)
        ->postJson('/api/user/devices', [
            'token' => 'fcm-a',
            'device_id' => 'install-1',
            'platform' => 'android',
            'app_version' => '1.0.0',
            'locale' => 'id',
        ])
        ->assertCreated()
        ->assertJsonPath('data.current_session', true)
        ->assertJsonMissingPath('data.token');

    $this->withToken($token->plainTextToken)
        ->postJson('/api/user/devices', ['token' => 'fcm-b', 'device_id' => 'install-1', 'platform' => 'android'])
        ->assertCreated();

    expect(DeviceToken::count())->toBe(1)
        ->and(DeviceToken::first()->token)->toBe('fcm-b')
        ->and(DeviceToken::first()->personal_access_token_id)->toBe($token->accessToken->id);
});

test('the same phone signing in as somebody else moves to that person', function () {
    [$owner, $agent] = pushWorkspace();

    Sanctum::actingAs($agent);
    $this->postJson('/api/user/devices', ['token' => 'fcm-a', 'device_id' => 'install-1', 'platform' => 'ios'])->assertCreated();

    Sanctum::actingAs($owner);
    $this->postJson('/api/user/devices', ['token' => 'fcm-a', 'device_id' => 'install-1', 'platform' => 'ios'])->assertCreated();

    expect(DeviceToken::count())->toBe(1)
        ->and(DeviceToken::first()->user_id)->toBe($owner->id);
});

test('a token reappearing under a new install replaces the old row', function () {
    [, $agent] = pushWorkspace();
    Sanctum::actingAs($agent);

    $this->postJson('/api/user/devices', ['token' => 'fcm-a', 'device_id' => 'install-1', 'platform' => 'android']);
    $this->postJson('/api/user/devices', ['token' => 'fcm-a', 'device_id' => 'install-2', 'platform' => 'android']);

    expect(DeviceToken::pluck('device_id')->all())->toBe(['install-2']);
});

test('logging out on the server removes the phone', function () {
    [, $agent] = pushWorkspace();
    $token = $agent->createToken('auth_token')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/user/devices', ['token' => 'fcm-a', 'device_id' => 'install-1', 'platform' => 'android'])
        ->assertCreated();

    $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

    expect(DeviceToken::count())->toBe(0)
        ->and($agent->tokens()->count())->toBe(0);
});

test('a phone can only be removed by its owner', function () {
    [$owner, $agent] = pushWorkspace();
    pushDevice($agent);

    Sanctum::actingAs($owner);
    $this->deleteJson("/api/user/devices/device-{$agent->id}-1")->assertNoContent();
    expect(DeviceToken::count())->toBe(1);

    Sanctum::actingAs($agent);
    $this->deleteJson("/api/user/devices/device-{$agent->id}-1")->assertNoContent();
    expect(DeviceToken::count())->toBe(0);
});

test('an incoming message reaches the owner and the agents who could pick it up', function () {
    Queue::fake();
    [$owner, $agent, $outsider, $conversation] = pushWorkspace();
    $ownerPhone = pushDevice($owner);
    $agentPhone = pushDevice($agent);
    pushDevice($outsider); // no access to the connection

    app(PushNotifier::class)->fanOut(PushNotifier::MESSAGE_RECEIVED, $conversation->id);

    expect(pushedDeviceIds())->toBe([$ownerPhone->id, $agentPhone->id]);
});

test('an active thread only reaches its assignee and the owner', function () {
    Queue::fake();
    [$owner, $agent, , $conversation] = pushWorkspace();
    $other = User::factory()->create(['tenant_id' => $owner->tenant_id]);
    $other->connections()->sync([$conversation->connection_id]);
    $conversation->forceFill(['status' => ConversationStatus::Active, 'user_id' => $other->id])->save();

    $ownerPhone = pushDevice($owner);
    pushDevice($agent);
    $otherPhone = pushDevice($other);

    app(PushNotifier::class)->fanOut(PushNotifier::MESSAGE_RECEIVED, $conversation->id);

    expect(pushedDeviceIds())->toBe([$ownerPhone->id, $otherPhone->id]);
});

test('muted threads and notification settings are honoured on the server', function () {
    Queue::fake();
    [$owner, $agent, , $conversation] = pushWorkspace();
    pushDevice($owner);
    $agentPhone = pushDevice($agent);

    $owner->forceFill(['notification_preferences' => ['incoming_messages' => false]])->save();
    app(PushNotifier::class)->fanOut(PushNotifier::MESSAGE_RECEIVED, $conversation->id);
    expect(pushedDeviceIds())->toBe([$agentPhone->id]);

    Queue::fake();
    $conversation->forceFill(['muted_at' => now()])->save();
    app(PushNotifier::class)->fanOut(PushNotifier::MESSAGE_RECEIVED, $conversation->id);
    app(PushNotifier::class)->fanOut(PushNotifier::HANDOFF, $conversation->id);
    expect(pushedDeviceIds())->toBe([]);
});

test('a transfer reaches only the receiving agent and never carries the message', function () {
    Queue::fake();
    [$owner, $agent, , $conversation] = pushWorkspace();
    pushDevice($owner);
    $agentPhone = pushDevice($agent);

    app(PushNotifier::class)->fanOut(PushNotifier::TRANSFERRED, $conversation->id, [
        'to_agent_id' => $agent->id,
        'agent_name' => 'Bruno',
    ]);

    $job = Queue::pushed(SendPushNotification::class)->first();
    expect($job->deviceTokenId)->toBe($agentPhone->id)
        ->and($job->message['notification']['title'])->toBe('Ana Souza')
        ->and($job->message['notification']['body'])->toContain('Bruno')
        ->and($job->message['data'])->toBe([
            'type' => 'conversation_transferred',
            'conversation_id' => (string) $conversation->id,
            'url' => '/conversations?conversation='.$conversation->id,
        ]);
});

test('the notification is worded in the language the phone registered with', function () {
    Queue::fake();
    [$owner, , , $conversation] = pushWorkspace();
    pushDevice($owner)->forceFill(['locale' => 'id'])->save();

    app(PushNotifier::class)->fanOut(PushNotifier::MESSAGE_RECEIVED, $conversation->id);

    expect(Queue::pushed(SendPushNotification::class)->first()->message['notification']['body'])->toBe('Pesan baru');
});

test('a broadcast incoming message queues the fan-out only when push is set up', function () {
    Queue::fake();
    [$owner, , , $conversation] = pushWorkspace();
    pushDevice($owner);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'segredo do cliente',
        'sent_at' => now()->timestamp,
    ]);

    event(new MessageReceived($message));
    Queue::assertNotPushed(FanOutPushNotification::class);

    FirebaseConfig::store(pushServiceAccountJson());
    event(new MessageReceived($message));
    Queue::assertPushed(FanOutPushNotification::class, 1);
});

test('a send records success, and a dead token is deleted', function () {
    FirebaseConfig::store(pushServiceAccountJson());
    [$owner] = pushWorkspace();
    $phone = pushDevice($owner);
    $dead = pushDevice($owner, '2');

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => fn ($request) => $request['message']['token'] === $dead->token
            ? Http::response(['error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404)
            : Http::response(['name' => 'projects/pingly-app/messages/1']),
    ]);

    (new SendPushNotification($phone->id, ['notification' => ['title' => 'x', 'body' => 'y']]))->handle(app(PushNotifier::class));
    (new SendPushNotification($dead->id, ['notification' => ['title' => 'x', 'body' => 'y']]))->handle(app(PushNotifier::class));

    expect($phone->fresh()->last_sent_at)->not->toBeNull()
        ->and(DeviceToken::find($dead->id))->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/projects/pingly-app/messages:send')
        && $request->hasHeader('Authorization', 'Bearer ya29.test'));
});

test('the test endpoint sends to the caller\'s own phones', function () {
    FirebaseConfig::store(pushServiceAccountJson());
    [, $agent] = pushWorkspace();
    pushDevice($agent);
    Sanctum::actingAs($agent);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/pingly-app/messages/1']),
    ]);

    $this->postJson('/api/user/devices/test')
        ->assertOk()
        ->assertJsonPath('data.0.ok', true);
});

function pushAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.settings.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin->fresh();
}

test('the back office uploads the service account file and never shows the key', function () {
    Sanctum::actingAs(pushAdmin());

    $this->postJson('/api/admin/firebase', ['service_account' => '{"type":"user"}'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('service_account');

    $file = UploadedFile::fake()->createWithContent('pingly-app.json', pushServiceAccountJson());

    $response = $this->post('/api/admin/firebase', ['file' => $file], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.project_id', 'pingly-app');

    expect($response->getContent())->not->toContain('PRIVATE KEY')
        ->and(FirebaseConfig::clientEmail())->toBe('push@pingly-app.iam.gserviceaccount.com');

    $this->deleteJson('/api/admin/firebase')->assertOk()->assertJsonPath('data.configured', false);
});

test('the back office test reports a working credential', function () {
    Sanctum::actingAs(pushAdmin());
    FirebaseConfig::store(pushServiceAccountJson());

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 400, 'message' => 'The registration token is not a valid FCM registration token', 'status' => 'INVALID_ARGUMENT']], 400),
    ]);

    $this->postJson('/api/admin/firebase/test')->assertOk()->assertJsonPath('data.ok', true);
});
