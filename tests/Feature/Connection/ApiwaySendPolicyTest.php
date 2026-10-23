<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\SenderType;
use App\Events\MessageUpdated;
use App\Models\ApiwayQueuedSend;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Apiway\ApiwaySendPolicy;
use App\Services\Message\ApiwaySendQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The API Way anti-ban policy, read and changed from a connection.
 *
 * The core documents the fields but not every edge of them, and nobody here
 * can ask: so "no daily limit" is a huge number rather than 0, the queue is
 * never switched off, and what the core stored is read back instead of
 * assumed.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.billing.enforce' => false]);
    Event::fake([MessageUpdated::class]);
});

function policyConnection(Channel $channel = Channel::WhatsappApiway): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'N1',
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-1', 'token' => 'inst-token'],
    ]);
}

function policyOwner(Connection $connection, bool $canUpdate = true): User
{
    Role::findOrCreate('owner', 'web');
    Permission::findOrCreate('connections.update', 'web');

    $owner = User::where('tenant_id', $connection->tenant_id)->firstOrFail();
    $owner->assignRole('owner');

    if ($canUpdate) {
        $owner->givePermissionTo('connections.update');
    }

    return $owner;
}

/** The core's policy, as GET /v1/instance/send-policy answers it. */
function corePolicy(array $overrides = []): array
{
    return array_merge([
        'instanceId' => 'INST-1',
        'queue_enabled' => true,
        'msgs_per_min' => 13,
        'min_delay_ms' => 3000,
        'max_delay_ms' => 8000,
        'daily_cap' => 800,
        'typing_enabled' => true,
        'auto_throttle_enabled' => true,
        'alert_webhook_url' => null,
        'effective_msgs_per_min' => 13,
        'risk_level' => 'low',
        'risk_score' => 22,
        'limits' => ['max_msgs_per_min' => 60, 'high_risk_msgs_per_min' => 4],
    ], $overrides);
}

/**
 * A core that keeps the policy in memory: PUT changes it (unless told to
 * ignore some fields), GET returns it. Every request is recorded.
 */
function fakeCore(array &$policy, array $ignore = [], array $queue = [], array $sendLog = []): void
{
    Http::fake(function (HttpRequest $request) use (&$policy, $ignore, &$queue, $sendLog) {
        $url = $request->url();

        if (str_contains($url, '/v1/instance/send-policy') && $request->method() === 'PUT') {
            foreach ($request->data() as $field => $value) {
                if (! in_array($field, $ignore, true)) {
                    $policy[$field] = $value;
                }
            }

            return Http::response(['success' => true, 'data' => $policy]);
        }

        if (str_contains($url, '/v1/instance/send-policy')) {
            return Http::response(['success' => true, 'data' => $policy]);
        }

        if (str_contains($url, '/v1/instance/send-stats')) {
            return Http::response(['success' => true, 'data' => ['totals' => ['sent' => 800, 'queued' => count($queue)]]]);
        }

        if (str_contains($url, '/v1/instance/quere/clear-quere')) {
            $deleted = count($queue);
            $queue = [];

            return Http::response(['success' => true, 'data' => ['deleted' => $deleted]]);
        }

        if (str_contains($url, '/v1/instance/quere')) {
            return Http::response(['success' => true, 'data' => $queue]);
        }

        if (str_contains($url, '/v1/instance/send-log')) {
            return Http::response(['success' => true, 'data' => $sendLog, 'next_cursor' => null]);
        }

        return Http::response([], 404);
    });
}

test('the panel shows the policy, today against the cap and what is waiting', function () {
    $connection = policyConnection();
    $policy = corePolicy();
    fakeCore($policy, queue: [
        ['id' => 'q-1', 'kind' => 'send-text', 'status' => 'queued', 'scheduled_at' => '2026-10-24T00:00:00Z'],
        ['id' => 'q-2', 'kind' => 'send-text', 'status' => 'queued', 'scheduled_at' => '2026-10-24T00:00:05Z'],
    ]);

    $data = $this->actingAs(policyOwner($connection), 'sanctum')
        ->getJson("/api/connections/{$connection->id}/apiway/send-policy")
        ->assertOk()
        ->json('data');

    expect($data['policy'])->toMatchArray(['daily_cap' => 800, 'msgs_per_min' => 13, 'queue_enabled' => true])
        ->and($data['risk'])->toBe(['level' => 'low', 'score' => 22])
        ->and($data['today']['sent'])->toBe(800)
        ->and($data['queue'])->toBe(['count' => 2, 'next_at' => '2026-10-24T00:00:00Z'])
        ->and($data['limits']['unlimited_daily_cap'])->toBe(ApiwaySendPolicy::UNLIMITED_DAILY_CAP);
});

test('no daily limit is sent as a huge number, never as 0', function () {
    $connection = policyConnection();
    $owner = policyOwner($connection);
    $policy = corePolicy();
    fakeCore($policy);

    $data = $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['daily_cap_unlimited' => true])
        ->assertOk()
        ->json('data');

    expect($data['policy']['daily_cap'])->toBe(999999)
        ->and($data['not_applied'])->toBe([]);

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
        && $request->data() === ['daily_cap' => 999999]
        && $request->hasHeader('X-ApiWay-Source', 'pingly')
        && $request->hasHeader('X-ApiWay-Actor', "user:{$owner->id}")
        && str_contains($request->url(), 'instanceId=INST-1'));

    $audit = AuditLog::where('action', 'connection.apiway_send_policy_updated')->firstOrFail();

    expect($audit->metadata['before'])->toBe(['daily_cap' => 800])
        ->and($audit->metadata['after'])->toBe(['daily_cap' => 999999]);
});

test('a daily cap of 0, and a gap shorter than a second, are refused before the core is asked', function () {
    $connection = policyConnection();
    $owner = policyOwner($connection);
    $policy = corePolicy();
    fakeCore($policy);

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['daily_cap' => 0])
        ->assertStatus(422)->assertJsonValidationErrors('daily_cap');

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['min_delay_ms' => 0])
        ->assertStatus(422)->assertJsonValidationErrors('min_delay_ms');

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['msgs_per_min' => 61])
        ->assertStatus(422)->assertJsonValidationErrors('msgs_per_min');

    Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'PUT');
});

test('the queue can be switched back on, never off', function () {
    $connection = policyConnection();
    $owner = policyOwner($connection);
    $policy = corePolicy(['queue_enabled' => false]);
    fakeCore($policy);

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['queue_enabled' => false])
        ->assertStatus(422)->assertJsonValidationErrors('queue_enabled');

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['queue_enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.policy.queue_enabled', true);
});

test('a shortest gap past the stored longest one is refused against what is stored', function () {
    $connection = policyConnection();
    $owner = policyOwner($connection);
    $policy = corePolicy(['min_delay_ms' => 3000, 'max_delay_ms' => 8000]);
    fakeCore($policy);

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['min_delay_ms' => 9000])
        ->assertStatus(422)->assertJsonValidationErrors('max_delay_ms');

    Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'PUT');
});

test('a value the core did not keep is reported, not assumed', function () {
    $connection = policyConnection();
    $policy = corePolicy();
    fakeCore($policy, ignore: ['daily_cap']);

    $this->actingAs(policyOwner($connection), 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['daily_cap' => 2000, 'msgs_per_min' => 30])
        ->assertOk()
        ->assertJsonPath('data.policy.daily_cap', 800)
        ->assertJsonPath('data.policy.msgs_per_min', 30)
        ->assertJsonPath('data.not_applied', ['daily_cap']);
});

test('a change makes the queue follower look again at messages held for later', function () {
    $connection = policyConnection();
    $policy = corePolicy();
    fakeCore($policy);

    $message = policyQueuedMessage($connection, 'q-1');
    ApiwayQueuedSend::where('message_id', $message->id)->update(['check_after' => now()->addHours(10)]);

    $this->actingAs(policyOwner($connection), 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['daily_cap_unlimited' => true])
        ->assertOk();

    expect(ApiwayQueuedSend::first()->check_after)->toBeNull();
});

test('changing the policy takes connections.update; reading it does not', function () {
    $connection = policyConnection();
    $user = policyOwner($connection, canUpdate: false);
    $policy = corePolicy();
    fakeCore($policy);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/connections/{$connection->id}/apiway/send-policy")
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['daily_cap' => 2000])
        ->assertForbidden();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/connections/{$connection->id}/apiway/send-queue")
        ->assertForbidden();
});

test('a connection without an API Way instance has no policy to show', function () {
    $connection = policyConnection(Channel::Telegram);
    $policy = corePolicy();
    fakeCore($policy);

    $this->actingAs(policyOwner($connection), 'sanctum')
        ->getJson("/api/connections/{$connection->id}/apiway/send-policy")
        ->assertStatus(422);

    Http::assertNothingSent();
});

test('another workspace cannot read the policy of a connection', function () {
    $connection = policyConnection();
    $stranger = policyOwner(policyConnection());
    $policy = corePolicy();
    fakeCore($policy);

    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/connections/{$connection->id}/apiway/send-policy")
        ->assertNotFound();
});

test('emptying the queue marks what was removed, and leaves what went out meanwhile as sent', function () {
    $connection = policyConnection();
    $removed = policyQueuedMessage($connection, 'q-removed');
    $wentOut = policyQueuedMessage($connection, 'q-sent');

    $policy = corePolicy();
    fakeCore($policy, queue: [
        ['id' => 'q-removed', 'status' => 'queued', 'scheduled_at' => '2026-10-24T00:00:00Z'],
        ['id' => 'q-sent', 'status' => 'queued', 'scheduled_at' => '2026-10-24T00:00:00Z'],
    ], sendLog: [
        ['id' => 'q-removed', 'status' => 'queued', 'scheduled_at' => '2026-10-24T00:00:00Z', 'sent_at' => null, 'error' => null],
        ['id' => 'q-sent', 'status' => 'sent', 'scheduled_at' => '2026-10-23T12:00:00Z', 'sent_at' => '2026-10-23T12:00:03Z', 'error' => null],
    ]);

    $this->actingAs(policyOwner($connection), 'sanctum')
        ->deleteJson("/api/connections/{$connection->id}/apiway/send-queue")
        ->assertOk()
        ->assertJsonPath('data.deleted', 2);

    expect($removed->fresh()->meta['send_queue'])->toMatchArray(['status' => 'failed', 'error_code' => 'canceled'])
        ->and($removed->fresh()->error)->toBe('The message was removed from the API Way queue before it was sent.')
        ->and($wentOut->fresh()->meta['send_queue']['status'])->toBe('sent')
        ->and(ApiwayQueuedSend::count())->toBe(0)
        ->and(AuditLog::where('action', 'connection.apiway_send_queue_cleared')->exists())->toBeTrue();
});

function policyQueuedMessage(Connection $connection, string $queueId): Message
{
    $contact = Contact::firstOrCreate(
        ['tenant_id' => $connection->tenant_id, 'external_id' => '5511932951604'],
        ['name' => 'Cliente'],
    );

    $conversation = Conversation::firstOrCreate(
        ['connection_id' => $connection->id, 'contact_id' => $contact->id],
        ['tenant_id' => $connection->tenant_id, 'external_id' => '5511932951604', 'status' => ConversationStatus::Pending],
    );

    $message = $conversation->messages()->create([
        'external_id' => $queueId,
        'sender_type' => SenderType::Outgoing,
        'message_type' => 'text',
        'body' => 'oi',
        'sent_at' => now(),
        'meta' => ['messageId' => $queueId, 'status' => 'queued'],
    ]);

    ApiwaySendQueue::track($message, $connection);

    return $message->fresh();
}

test('a level is applied as its values, and the panel names the level the number is on', function () {
    $connection = policyConnection();
    $owner = policyOwner($connection);
    $policy = corePolicy();
    fakeCore($policy);

    $data = $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['preset' => 'moderate'])
        ->assertOk()
        ->json('data');

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT' && $request->data() === [
        'daily_cap' => 1500,
        'msgs_per_min' => 20,
        'min_delay_ms' => 2000,
        'max_delay_ms' => 4000,
        'auto_throttle_enabled' => true,
    ]);

    expect($data['preset'])->toBe('moderate')
        ->and(array_column($data['presets'], 'key'))->toBe(['default', 'moderate', 'high', 'very_high', 'unlimited']);
});

test('the no-limits level still never sends a daily cap of 0', function () {
    $connection = policyConnection();
    $policy = corePolicy();
    fakeCore($policy);

    $this->actingAs(policyOwner($connection), 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['preset' => 'unlimited'])
        ->assertOk()
        ->assertJsonPath('data.preset', 'unlimited')
        ->assertJsonPath('data.policy.daily_cap', 999999);

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PUT'
        && $request->data()['daily_cap'] === 999999
        && $request->data()['auto_throttle_enabled'] === false
        && ! array_key_exists('queue_enabled', $request->data()));
});

test('a level overrides fields sent alongside it, and an unknown level is refused', function () {
    $connection = policyConnection();
    $owner = policyOwner($connection);
    $policy = corePolicy();
    fakeCore($policy);

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['preset' => 'high', 'daily_cap' => 50])
        ->assertOk()
        ->assertJsonPath('data.policy.daily_cap', 3000);

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}/apiway/send-policy", ['preset' => 'insane'])
        ->assertStatus(422)->assertJsonValidationErrors('preset');
});

test('a hand-tuned policy is on no level', function () {
    $connection = policyConnection();
    $policy = corePolicy(['daily_cap' => 1000]);
    fakeCore($policy);

    $this->actingAs(policyOwner($connection), 'sanctum')
        ->getJson("/api/connections/{$connection->id}/apiway/send-policy")
        ->assertOk()
        ->assertJsonPath('data.preset', null);
});

test('the levels can be listed before any instance exists', function () {
    $connection = policyConnection();
    Http::fake();

    $data = $this->actingAs(policyOwner($connection), 'sanctum')
        ->getJson('/api/connections/apiway/send-policy/presets')
        ->assertOk()
        ->json('data');

    expect(array_column($data['presets'], 'key'))->toBe(['default', 'moderate', 'high', 'very_high', 'unlimited'])
        ->and($data['presets'][0]['daily_cap'])->toBe(800)
        ->and($data['unlimited_daily_cap'])->toBe(999999);

    Http::assertNothingSent();
});
