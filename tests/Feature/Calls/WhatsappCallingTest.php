<?php

use App\Enums\Call\CallStatus;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\CallUpdated;
use App\Events\ConversationTakenOver;
use App\Events\IncomingCall;
use App\Jobs\SendPushNotification;
use App\Models\Call;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Calls\CallService;
use App\Services\Push\FirebaseConfig;
use App\Services\Webhook\Handlers\Chat\WhatsappCallHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const WC_CUSTOMER = '5511977776666';
const WC_CALL_ID = 'wacid.HBgMNTUxMTk3Nzc3NjY2Nh';
const WC_OFFER = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=-\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n";
const WC_ANSWER = "v=0\r\no=- 3 4 IN IP4 127.0.0.1\r\ns=-\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\na=answer\r\n";

/** @return array{0: Connection, 1: User} the connection and its owner */
function wcWorkspace(array $connection = []): array
{
    Role::findOrCreate('owner', 'web');

    $owner = User::factory()->create(['last_seen_at' => now()]);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    $owner->assignRole('owner');
    $owner->givePermissionTo('calls.answer');

    $conn = Connection::create(array_merge([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'Vendas',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'phone_number_id' => '106540352242922',
            'access_token' => 'test-token',
            'business_account_id' => '111222333444555',
        ],
    ], $connection));

    return [$conn, $owner->fresh()];
}

/** An agent of the workspace, by default rung for everything. */
function wcAgent(Connection $connection, array $attributes = [], bool $access = true, bool $permission = true): User
{
    $agent = User::factory()->create(array_merge([
        'tenant_id' => $connection->tenant_id,
        'last_seen_at' => now(),
    ], $attributes));

    if ($access) {
        $agent->connections()->sync([$connection->id]);
    }

    if ($permission) {
        $agent->givePermissionTo('calls.answer');
    }

    return $agent->fresh();
}

function wcServiceAccount(): string
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

function wcGraph(array $overrides = []): void
{
    Http::fake(array_merge([
        'graph.facebook.com/*/calls' => Http::response(['messaging_product' => 'whatsapp', 'success' => true]),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.BUSY']]]),
        'graph.facebook.com/*/settings' => Http::response(['calling' => ['status' => 'ENABLED']]),
    ], $overrides));
}

function wcChange(array $call): array
{
    return [
        'field' => 'calls',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => '106540352242922'],
            'contacts' => [['profile' => ['name' => 'Carla Dias'], 'wa_id' => WC_CUSTOMER]],
            'calls' => [array_merge([
                'id' => WC_CALL_ID,
                'from' => WC_CUSTOMER,
                'direction' => 'USER_INITIATED',
                'timestamp' => (string) now()->timestamp,
            ], $call)],
        ],
    ];
}

function wcConnect(Connection $connection): ?Call
{
    app(WhatsappCallHandler::class)->handleChange($connection, wcChange([
        'event' => 'connect',
        'session' => ['sdp_type' => 'offer', 'sdp' => WC_OFFER],
    ]));

    return Call::query()->where('external_id', WC_CALL_ID)->first();
}

function wcTerminate(Connection $connection, array $call = []): void
{
    app(WhatsappCallHandler::class)->handleChange($connection, wcChange(array_merge([
        'event' => 'terminate',
        'status' => 'COMPLETED',
    ], $call)));
}

/** A thread the customer already wrote in, so the reply window is open. */
function wcOpenThread(Connection $connection, array $conversation = []): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => WC_CUSTOMER,
        'name' => 'Carla Dias',
        'channel' => $connection->channel,
    ]);

    $thread = Conversation::create(array_merge([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => WC_CUSTOMER,
        'status' => ConversationStatus::Pending,
    ], $conversation));

    $thread->messages()->create([
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Oi, vou te ligar',
        'sent_at' => now(),
    ]);

    return $thread;
}

function wcGraphActions(): array
{
    return Http::recorded()
        ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/calls'))
        ->map(fn ($pair) => $pair[0]['action'])
        ->values()
        ->all();
}

function wcNote(): ?Message
{
    return Message::query()->where('message_type', MessageType::Info)->where('external_id', 'call:'.WC_CALL_ID)->first();
}

// ------------------------------------------------------------------ ringing

test('an incoming call rings every agent who can answer it, and nobody else', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection, $owner] = wcWorkspace();

    $online = wcAgent($connection);
    $offlineSwitch = wcAgent($connection, ['offline_since' => now()]);
    $stale = wcAgent($connection, ['last_seen_at' => now()->subHour()]);
    $noAccess = wcAgent($connection, access: false);
    $noPermission = wcAgent($connection, permission: false);
    $optedOut = wcAgent($connection, ['notification_preferences' => ['calls' => false]]);

    $call = wcConnect($connection);

    expect($call)->not->toBeNull()
        ->and($call->status)->toBe(CallStatus::Ringing)
        ->and($call->offer_sdp)->toBe(WC_OFFER)
        ->and($call->conversation_id)->not->toBeNull()
        ->and(collect($call->rung_user_ids)->sort()->values()->all())
        ->toBe(collect([$owner->id, $online->id])->sort()->values()->all());

    Event::assertDispatched(IncomingCall::class, fn ($event) => collect($event->userIds)->sort()->values()->all()
        === collect([$owner->id, $online->id])->sort()->values()->all());
    Event::assertDispatched(CallUpdated::class);

    // The note is still the record in the thread, ringing.
    expect(wcNote()->meta['info']['code'])->toBe('call_ringing');

    // Nothing was refused.
    expect(wcGraphActions())->toBe([]);

    unset($offlineSwitch, $stale, $noAccess, $noPermission, $optedOut);
});

test('an agent already on a call is not rung for the next one', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection, $owner] = wcWorkspace();
    $busy = wcAgent($connection);
    $free = wcAgent($connection);

    Call::create([
        'tenant_id' => $connection->tenant_id,
        'connection_id' => $connection->id,
        'channel' => Channel::WhatsappOfficial,
        'external_id' => 'wacid.OTHER',
        'status' => CallStatus::Ongoing,
        'answered_by_user_id' => $busy->id,
        'answered_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    $call = wcConnect($connection);

    expect($call->rung_user_ids)->not->toContain($busy->id)
        ->and($call->rung_user_ids)->toContain($free->id);
});

test('a phone with the app is rung even when its dashboard is not open', function () {
    Queue::fake([SendPushNotification::class]);
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    FirebaseConfig::store(wcServiceAccount());
    [$connection, $owner] = wcWorkspace();
    $owner->forceFill(['last_seen_at' => null])->save();

    $phoneOnly = wcAgent($connection, ['last_seen_at' => null]);
    $device = DeviceToken::create([
        'tenant_id' => $connection->tenant_id,
        'user_id' => $phoneOnly->id,
        'device_id' => 'device-1',
        'token' => 'fcm-1',
        'platform' => 'android',
        'last_registered_at' => now(),
    ]);

    $call = wcConnect($connection);

    expect($call->rung_user_ids)->toBe([$phoneOnly->id]);

    Queue::assertPushed(SendPushNotification::class, function ($job) use ($device, $call) {
        return $job->deviceTokenId === $device->id
            && $job->message['data']['type'] === 'incoming_call'
            && $job->message['data']['call_id'] === (string) $call->id
            && $job->message['android']['priority'] === 'high'
            // Data-only on Android: the app draws its own call screen.
            && ! isset($job->message['notification']);
    });
});

test('with nobody to answer, the call is refused at once and the busy message goes to the chat', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection, $owner] = wcWorkspace(['call_busy_message' => 'Estamos em outra ligação, já te chamamos.']);
    $owner->forceFill(['offline_since' => now()])->save();
    $thread = wcOpenThread($connection);

    $call = wcConnect($connection);

    expect($call->status)->toBe(CallStatus::Rejected)
        ->and($call->end_reason)->toBe(Call::REASON_NO_AGENT)
        ->and($call->conversation_id)->toBe($thread->id)
        ->and(wcGraphActions())->toBe(['reject'])
        ->and(wcNote()->meta['info']['code'])->toBe('call_busy');

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/messages')
        && str_contains(json_encode($request->data()), 'Estamos em outra liga'));

    Event::assertNotDispatched(IncomingCall::class);

    // Meta's terminate for the refused call does not turn it into a plain
    // missed call.
    wcTerminate($connection);
    expect(wcNote()->fresh()->meta['info']['code'])->toBe('call_busy');
});

test('without a busy message the call is refused silently', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection, $owner] = wcWorkspace();
    $owner->forceFill(['offline_since' => now()])->save();
    wcOpenThread($connection);

    wcConnect($connection);

    Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/messages'));
});

test('a re-delivered connect does not ring anybody twice', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();

    wcConnect($connection);
    wcConnect($connection);

    expect(Call::count())->toBe(1);
    Event::assertDispatchedTimes(IncomingCall::class, 1);
});

// ---------------------------------------------------------------- answering

test('answering walks claim, pre_accept and accept, and hands the thread to the agent', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();
    $agent = wcAgent($connection);
    $thread = wcOpenThread($connection);

    $call = wcConnect($connection);
    Sanctum::actingAs($agent);

    $claim = $this->postJson("/api/calls/{$call->id}/claim")
        ->assertOk()
        ->assertJsonPath('offer_sdp', WC_OFFER)
        ->assertJsonPath('data.status', 'connecting')
        ->assertJsonPath('data.agent.id', $agent->id);

    expect($claim->json('ice_servers'))->toBeArray()
        // The SDPs never ride on the shared resource.
        ->and($claim->json('data'))->not->toHaveKey('offer_sdp');

    $this->postJson("/api/calls/{$call->id}/answer", ['sdp' => WC_ANSWER])->assertOk();
    $this->postJson("/api/calls/{$call->id}/accept")->assertOk()->assertJsonPath('data.status', 'ongoing');

    $recorded = Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/calls'))->values();
    expect($recorded->map(fn ($pair) => $pair[0]['action'])->all())->toBe(['pre_accept', 'accept'])
        // accept repeats the pre_accept answer byte for byte.
        ->and($recorded[0][0]['session'])->toBe(['sdp_type' => 'answer', 'sdp' => WC_ANSWER])
        ->and($recorded[1][0]['session'])->toBe(['sdp_type' => 'answer', 'sdp' => WC_ANSWER]);

    $call->refresh();
    expect($call->status)->toBe(CallStatus::Ongoing)
        ->and($call->answered_at)->not->toBeNull()
        ->and($call->offer_sdp)->toBeNull();

    $thread->refresh();
    expect($thread->status)->toBe(ConversationStatus::Active)
        ->and($thread->user_id)->toBe($agent->id);

    $note = wcNote();
    expect($note->meta['info']['code'])->toBe('call_ongoing_by')
        ->and($note->meta['info']['params']['agent'])->toBe($agent->name);

    // Nothing was sent to the customer: they are already talking.
    Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/messages'));
});

test('the first agent to answer wins', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();
    $ana = wcAgent($connection, ['name' => 'Ana']);
    $bruno = wcAgent($connection, ['name' => 'Bruno']);

    $call = wcConnect($connection);

    Sanctum::actingAs($ana);
    $this->postJson("/api/calls/{$call->id}/claim")->assertOk();

    Sanctum::actingAs($bruno);
    $this->postJson("/api/calls/{$call->id}/claim")
        ->assertStatus(409)
        ->assertJsonPath('code', 'call_already_answered');
});

test('answering a call on a colleague\'s thread takes the thread over', function () {
    Event::fake([IncomingCall::class, CallUpdated::class, ConversationTakenOver::class]);
    wcGraph();
    [$connection] = wcWorkspace();
    $holder = wcAgent($connection, ['name' => 'Holder']);
    $answerer = wcAgent($connection, ['name' => 'Answerer']);
    $thread = wcOpenThread($connection, ['status' => ConversationStatus::Active, 'user_id' => $holder->id]);

    $call = wcConnect($connection);
    expect($call->rung_user_ids)->toContain($answerer->id);

    Sanctum::actingAs($answerer);
    $this->postJson("/api/calls/{$call->id}/claim")->assertOk();
    $this->postJson("/api/calls/{$call->id}/answer", ['sdp' => WC_ANSWER])->assertOk();
    $this->postJson("/api/calls/{$call->id}/accept")->assertOk();

    expect($thread->fresh()->user_id)->toBe($answerer->id);
    expect(Message::query()->where('meta->info->code', 'conversation_taken_over')->exists())->toBeTrue();
    Event::assertDispatched(ConversationTakenOver::class);
});

test('a pre_accept Meta refuses gives the call back to the others', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph(['graph.facebook.com/*/calls' => Http::response(['error' => ['message' => '(#100) Invalid parameter', 'code' => 100]], 400)]);
    [$connection] = wcWorkspace();
    $agent = wcAgent($connection);

    $call = wcConnect($connection);
    Sanctum::actingAs($agent);
    $this->postJson("/api/calls/{$call->id}/claim")->assertOk();
    $this->postJson("/api/calls/{$call->id}/answer", ['sdp' => WC_ANSWER])->assertStatus(422);

    $call->refresh();
    expect($call->status)->toBe(CallStatus::Ringing)
        ->and($call->answered_by_user_id)->toBeNull();
});

test('when everybody rung declines, the call is refused', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection, $owner] = wcWorkspace();
    $agent = wcAgent($connection);

    $call = wcConnect($connection);

    Sanctum::actingAs($agent);
    $this->postJson("/api/calls/{$call->id}/decline")->assertOk()->assertJsonPath('data.status', 'ringing');
    expect(wcGraphActions())->toBe([]);

    Sanctum::actingAs($owner);
    $this->postJson("/api/calls/{$call->id}/decline")->assertOk()->assertJsonPath('data.status', 'rejected');

    expect(wcGraphActions())->toBe(['reject'])
        ->and($call->fresh()->end_reason)->toBe(Call::REASON_DECLINED);
});

test('an agent without the permission cannot claim', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();
    $agent = wcAgent($connection, permission: false);

    $call = wcConnect($connection);
    Sanctum::actingAs($agent);

    $this->postJson("/api/calls/{$call->id}/claim")->assertForbidden();
});

// --------------------------------------------------------------- ending it

test('the customer hanging up ends the call with Meta\'s duration and the agent\'s name', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();
    $agent = wcAgent($connection, ['name' => 'Ana']);

    $call = wcConnect($connection);
    Sanctum::actingAs($agent);
    $this->postJson("/api/calls/{$call->id}/claim");
    $this->postJson("/api/calls/{$call->id}/answer", ['sdp' => WC_ANSWER]);
    $this->postJson("/api/calls/{$call->id}/accept");

    wcTerminate($connection, ['duration' => 95, 'start_time' => (string) now()->timestamp, 'end_time' => (string) now()->timestamp]);

    $call->refresh();
    expect($call->status)->toBe(CallStatus::Ended)
        ->and($call->end_reason)->toBe(Call::REASON_CUSTOMER_HUNG_UP)
        ->and($call->duration_seconds)->toBe(95)
        ->and($call->answer_sdp)->toBeNull();

    $note = wcNote()->fresh();
    expect($note->meta['info']['code'])->toBe('call_answered_by')
        ->and($note->meta['info']['params'])->toBe(['seconds' => 95, 'agent' => 'Ana']);
});

test('the agent hanging up terminates the call at Meta', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();
    $agent = wcAgent($connection);

    $call = wcConnect($connection);
    Sanctum::actingAs($agent);
    $this->postJson("/api/calls/{$call->id}/claim");
    $this->postJson("/api/calls/{$call->id}/answer", ['sdp' => WC_ANSWER]);
    $this->postJson("/api/calls/{$call->id}/accept");
    $this->postJson("/api/calls/{$call->id}/hangup")->assertOk()->assertJsonPath('data.status', 'ended');

    expect(wcGraphActions())->toBe(['pre_accept', 'accept', 'terminate'])
        ->and($call->fresh()->end_reason)->toBe(Call::REASON_AGENT_HUNG_UP);
});

test('a call that rings out is missed and the customer gets the busy message', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace(['call_busy_message' => 'Não conseguimos atender agora.']);
    wcOpenThread($connection);

    $call = wcConnect($connection);
    wcTerminate($connection);

    expect($call->fresh()->status)->toBe(CallStatus::Missed)
        ->and($call->fresh()->end_reason)->toBe(Call::REASON_UNANSWERED)
        ->and(wcNote()->fresh()->meta['info']['code'])->toBe('call_missed');

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/messages'));
});

test('the sweep ends a call whose browser stopped reporting in, and one that never stopped ringing', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();
    $agent = wcAgent($connection);

    $call = wcConnect($connection);
    Sanctum::actingAs($agent);
    $this->postJson("/api/calls/{$call->id}/claim");
    $this->postJson("/api/calls/{$call->id}/answer", ['sdp' => WC_ANSWER]);
    $this->postJson("/api/calls/{$call->id}/accept");

    $call->forceFill(['last_heartbeat_at' => now()->subMinutes(5)])->save();

    $stuck = Call::create([
        'tenant_id' => $connection->tenant_id,
        'connection_id' => $connection->id,
        'channel' => Channel::WhatsappOfficial,
        'external_id' => 'wacid.STUCK',
        'status' => CallStatus::Ringing,
        'ringing_at' => now()->subMinutes(10),
    ]);

    $counts = app(CallService::class)->sweep();

    expect($counts)->toBe(['expired' => 1, 'failed' => 0, 'lost' => 1])
        ->and($call->fresh()->status)->toBe(CallStatus::Ended)
        ->and($call->fresh()->end_reason)->toBe(Call::REASON_LOST)
        ->and($stuck->fresh()->status)->toBe(CallStatus::Missed)
        ->and(wcGraphActions())->toContain('terminate');
});

test('a heartbeat keeps the call alive and reports when it is over', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();
    $agent = wcAgent($connection);

    $call = wcConnect($connection);
    Sanctum::actingAs($agent);
    $this->postJson("/api/calls/{$call->id}/claim");
    $this->postJson("/api/calls/{$call->id}/answer", ['sdp' => WC_ANSWER]);
    $this->postJson("/api/calls/{$call->id}/accept");

    $this->postJson("/api/calls/{$call->id}/heartbeat")->assertOk()->assertJsonPath('status', 'ongoing');

    wcTerminate($connection, ['duration' => 12]);

    $this->postJson("/api/calls/{$call->id}/heartbeat")->assertOk()->assertJsonPath('status', 'ended');
});

// ------------------------------------------------------------ who can see it

test('live calls are listed only to people who can reach the connection', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();
    $agent = wcAgent($connection, ['name' => 'Ana']);
    $outsider = wcAgent($connection, access: false);

    $call = wcConnect($connection);
    Sanctum::actingAs($agent);
    $this->postJson("/api/calls/{$call->id}/claim");

    $this->getJson('/api/calls/active')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'connecting')
        ->assertJsonPath('data.0.agent.name', 'Ana')
        ->assertJsonPath('data.0.contact.name', 'Carla Dias');

    Sanctum::actingAs($outsider);
    $this->getJson('/api/calls/active')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/calls/{$call->id}")->assertNotFound();
});

test('the Live board shows who is on a call', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection, $owner] = wcWorkspace();
    $agent = wcAgent($connection);

    $call = wcConnect($connection);
    Sanctum::actingAs($agent);
    $this->postJson("/api/calls/{$call->id}/claim");
    $this->postJson("/api/calls/{$call->id}/answer", ['sdp' => WC_ANSWER]);
    $this->postJson("/api/calls/{$call->id}/accept");

    $agents = \App\Services\Live\LiveMonitor::forUser($owner)->agents();
    $row = collect($agents)->firstWhere('id', $agent->id);

    expect($row['on_call'])->not->toBeNull()
        ->and($row['on_call']['conversation_id'])->toBe($call->conversation_id)
        ->and($row['on_call']['status'])->toBe('ongoing');
});

// --------------------------------------------------------- connection setup

test('calling can be switched on from the connection and its busy message saved', function () {
    wcGraph();
    [$connection, $owner] = wcWorkspace();
    $owner->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('connections.update', 'web'));
    Sanctum::actingAs($owner);

    $this->getJson("/api/connections/{$connection->id}/calling")
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.status', 'ENABLED');

    $this->putJson("/api/connections/{$connection->id}/calling", [
        'enabled' => true,
        'busy_message' => '  Já te ligamos de volta.  ',
    ])->assertOk()->assertJsonPath('data.busy_message', 'Já te ligamos de volta.');

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/settings')
        && $request->method() === 'POST'
        && $request['calling'] === ['status' => 'ENABLED']);

    expect($connection->fresh()->call_busy_message)->toBe('Já te ligamos de volta.');
});

test('a Coexistence number cannot ring the dashboard', function () {
    wcGraph();
    [$connection, $owner] = wcWorkspace([
        'credentials' => [
            'phone_number_id' => '106540352242922',
            'access_token' => 'test-token',
            'business_account_id' => '111222333444555',
            'is_coexistence' => true,
        ],
    ]);
    $owner->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('connections.update', 'web'));
    Sanctum::actingAs($owner);

    $this->getJson("/api/connections/{$connection->id}/calling")
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.unavailable_reason', 'coexistence');

    $this->putJson("/api/connections/{$connection->id}/calling", ['enabled' => true])
        ->assertStatus(422)
        ->assertJsonPath('code', 'coexistence');
});

test('the kill switch leaves calls as a note only', function () {
    config(['calls.enabled' => false]);
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();

    expect(wcConnect($connection))->toBeNull()
        ->and(wcNote())->not->toBeNull();

    Event::assertNotDispatched(IncomingCall::class);
    expect(wcGraphActions())->toBe([]);
});

// ------------------------------------------------------------ SDP integrity

test('the offer keeps its final CRLF when it arrives through the real webhook', function () {
    // The first production call: TrimStrings stripped the offer's last line
    // break on its way in through HTTP, and Chrome refused to parse it. The
    // other tests call the handler directly and could never have seen it.
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();

    \Tests\Support\MetaWebhook::post($this, [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '111222333444555',
            'changes' => [wcChange([
                'event' => 'connect',
                'session' => ['sdp_type' => 'offer', 'sdp' => WC_OFFER],
            ])],
        ]],
    ])->assertOk();

    $call = Call::query()->where('external_id', WC_CALL_ID)->firstOrFail();

    expect($call->offer_sdp)->toBe(WC_OFFER)
        ->and(str_ends_with($call->offer_sdp, "\r\n"))->toBeTrue();
});

test('an SDP is normalised to CRLF lines with a final line break', function () {
    expect(\App\Services\Calls\Sdp::normalize("v=0\no=- 1 2 IN IP4 0.0.0.0\ns=-"))
        ->toBe("v=0\r\no=- 1 2 IN IP4 0.0.0.0\r\ns=-\r\n")
        ->and(\App\Services\Calls\Sdp::normalize(WC_OFFER))->toBe(WC_OFFER)
        ->and(\App\Services\Calls\Sdp::normalize('   '))->toBeNull();
});

test('the browser answer reaches Meta with its final CRLF, and a setup failure is reported back', function () {
    Event::fake([IncomingCall::class, CallUpdated::class]);
    wcGraph();
    [$connection] = wcWorkspace();
    $agent = wcAgent($connection);

    $call = wcConnect($connection);
    Sanctum::actingAs($agent);
    $this->postJson("/api/calls/{$call->id}/claim")->assertOk();
    $this->postJson("/api/calls/{$call->id}/answer", ['sdp' => rtrim(WC_ANSWER)])->assertOk();

    $sent = Http::recorded()->first(fn ($pair) => str_ends_with($pair[0]->url(), '/calls'))[0];
    expect($sent['session']['sdp'])->toBe(WC_ANSWER);

    // A second call, given up in the browser before answering: the claim is
    // released and the browser's own error is what the log keeps.
    $this->postJson("/api/calls/{$call->id}/hangup", ['error' => 'OperationError: Failed to parse SessionDescription'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ringing');
});
