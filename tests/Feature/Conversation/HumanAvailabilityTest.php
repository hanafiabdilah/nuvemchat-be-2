<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Events\UserPresenceUpdated;
use App\Models\Connection;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use App\Services\HumanAvailabilityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\Support\ProactiveMessageFixtures as F;

/**
 * Whether a customer can be handed to a person: by the connection's service
 * hours (the old rule, still the default) or by an agent being online — and
 * the agent's own Online/Offline switch that the second mode reads.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('sign-in:127.0.0.1');
});

/** A schedule that is closed right now, whatever day the suite runs on. */
function haAlwaysClosed(string $awayMessage = 'Nossa equipe volta amanhã.'): array
{
    return [
        'enabled' => true,
        'timezone' => 'America/Sao_Paulo',
        'days' => ['mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []],
        'away_message' => $awayMessage,
    ];
}

/** A schedule that is open right now, whatever the time. */
function haAlwaysOpen(string $awayMessage = 'Nossa equipe volta amanhã.'): array
{
    $allDay = [['open' => '00:00', 'close' => '23:59']];

    return [
        'enabled' => true,
        'timezone' => 'America/Sao_Paulo',
        'days' => ['mon' => $allDay, 'tue' => $allDay, 'wed' => $allDay, 'thu' => $allDay, 'fri' => $allDay, 'sat' => $allDay, 'sun' => $allDay],
        'away_message' => $awayMessage,
    ];
}

/** An AI scene whose node may queue, on a connection configured as given. */
function haScene(array $connection): array
{
    $scene = F::scenario();

    $scene['node']->update([
        'data' => array_merge($scene['node']->data, ['service_hours_behavior' => 'handoff_in_hours']),
    ]);

    $scene['conversation']->connection->update($connection);

    return $scene;
}

/** An agent of the scene's workspace, given the scene's connection unless told otherwise. */
function haAgent(array $scene, bool $online = true, bool $withAccess = true): User
{
    $agent = User::factory()->create(['tenant_id' => $scene['tenant']->id]);

    if ($withAccess) {
        $agent->connections()->attach($scene['conversation']->connection_id);
    }

    if ($online) {
        $agent->markSeen();
    }

    return $agent;
}

/** The hub asks for a person, the way a run or a proactive message does. */
function haHandOff(array $scene): void
{
    $flowState = FlowState::where('conversation_id', $scene['conversation']->id)->firstOrFail();

    app(FlowExecutor::class)->handoffRequestedByHub($flowState, $scene['node']->fresh());
}

function haWasHandedOff(array $scene): bool
{
    return (bool) $scene['conversation']->fresh()->needs_human;
}

function haAwayMessageSent(array $scene): bool
{
    return Message::where('conversation_id', $scene['conversation']->id)
        ->where('body', 'Nossa equipe volta amanhã.')
        ->exists();
}

/* ------------------------------------------------- service hours (default) */

test('a connection keeps deciding by its service hours unless told otherwise', function () {
    $scene = haScene(['service_hours' => haAlwaysOpen()]);

    expect($scene['conversation']->connection->fresh()->human_availability)->toBe('service_hours');

    // Inside the hours with nobody signed in: the queue, exactly as before.
    haHandOff($scene);

    expect(haWasHandedOff($scene))->toBeTrue()
        ->and($scene['conversation']->fresh()->status)->toBe(ConversationStatus::Pending);
});

test('outside service hours an online agent does not make a person available', function () {
    $scene = haScene(['service_hours' => haAlwaysClosed(), 'human_availability' => 'service_hours']);
    haAgent($scene);

    haHandOff($scene);

    expect(haWasHandedOff($scene))->toBeFalse()
        ->and(haAwayMessageSent($scene))->toBeTrue();
});

/* ------------------------------------------------------- an agent online */

test('with an agent online the customer reaches a person outside the service hours', function () {
    $scene = haScene(['service_hours' => haAlwaysClosed(), 'human_availability' => 'online_agents']);
    haAgent($scene);

    haHandOff($scene);

    expect(haWasHandedOff($scene))->toBeTrue()
        ->and(haAwayMessageSent($scene))->toBeFalse();
});

test('with nobody online the AI keeps serving inside the service hours and the away message goes out', function () {
    $scene = haScene(['service_hours' => haAlwaysOpen(), 'human_availability' => 'online_agents']);
    haAgent($scene, online: false);

    haHandOff($scene);

    expect(haWasHandedOff($scene))->toBeFalse()
        ->and(haAwayMessageSent($scene))->toBeTrue()
        ->and($scene['conversation']->fresh()->status)->toBe(ConversationStatus::AiHandling);
});

test('an agent who switched to Offline does not count, dashboard open or not', function () {
    $scene = haScene(['service_hours' => haAlwaysOpen(), 'human_availability' => 'online_agents']);
    $agent = haAgent($scene);
    $agent->setPresence(false);

    expect(HumanAvailabilityGate::isAvailable($scene['conversation']->connection->fresh()))->toBeFalse();

    haHandOff($scene);

    expect(haWasHandedOff($scene))->toBeFalse();
});

test('an agent whose dashboard went quiet does not count, even switched to Online', function () {
    $scene = haScene(['human_availability' => 'online_agents']);
    $agent = haAgent($scene);

    // The laptop closed without the switch being flipped.
    $this->travel(10)->minutes();

    expect($agent->fresh()->isOnline())->toBeFalse()
        ->and(HumanAvailabilityGate::isAvailable($scene['conversation']->connection->fresh()))->toBeFalse();
});

test('only people who can open the connection count, and the owner always can', function () {
    $scene = haScene(['human_availability' => 'online_agents']);
    $connection = $scene['conversation']->connection->fresh();

    // Online, but given another inbox only.
    haAgent($scene, withAccess: false);
    expect(HumanAvailabilityGate::isAvailable($connection))->toBeFalse();

    // Someone online in another workspace changes nothing either.
    User::factory()->create()->markSeen();
    expect(HumanAvailabilityGate::isAvailable($connection))->toBeFalse();

    // The owner sees every connection without a pivot row to prove it.
    $owner = User::factory()->create(['tenant_id' => $scene['tenant']->id]);
    $owner->assignRole(Role::findOrCreate('owner', 'web'));
    $owner->markSeen();

    expect(HumanAvailabilityGate::isAvailable($connection))->toBeTrue()
        ->and(HumanAvailabilityGate::onlineAgents($connection)->pluck('id')->all())->toBe([$owner->id]);
});

/* ------------------------------------------------- the connection setting */

test('the service-hours endpoint reads and saves the choice next to the schedule', function () {
    $scene = haScene(['human_availability' => 'service_hours']);
    $owner = User::where('tenant_id', $scene['tenant']->id)->firstOrFail();
    $owner->givePermissionTo(
        \Spatie\Permission\Models\Permission::findOrCreate('service-hours.view', 'web'),
        \Spatie\Permission\Models\Permission::findOrCreate('service-hours.update', 'web'),
    );
    $connectionId = $scene['conversation']->connection_id;
    haAgent($scene);

    $this->actingAs($owner, 'sanctum')
        ->getJson("/api/connections/{$connectionId}/service-hours")
        ->assertOk()
        ->assertJsonPath('data.human_availability', 'service_hours')
        ->assertJsonPath('data.online_agents_now', 1);

    $body = array_merge(haAlwaysOpen(), ['human_availability' => 'online_agents']);

    $this->putJson("/api/connections/{$connectionId}/service-hours", $body)
        ->assertOk()
        ->assertJsonPath('data.human_availability', 'online_agents');

    // A client that predates the choice does not reset it by leaving it out.
    $this->putJson("/api/connections/{$connectionId}/service-hours", haAlwaysOpen())->assertOk();
    expect(Connection::find($connectionId)->human_availability)->toBe('online_agents');

    $this->putJson("/api/connections/{$connectionId}/service-hours", array_merge(haAlwaysOpen(), ['human_availability' => 'whenever']))
        ->assertUnprocessable();
});

/* -------------------------------------------------------- the agent switch */

test('an agent switches to Offline and every tab hears it', function () {
    Event::fake([UserPresenceUpdated::class]);

    $scene = haScene([]);
    $agent = haAgent($scene);

    $this->actingAs($agent, 'sanctum')
        ->putJson('/api/user/presence', ['status' => 'offline'])
        ->assertOk()
        ->assertJsonPath('presence', 'offline');

    expect($agent->fresh()->offline_since)->not->toBeNull()
        ->and($agent->fresh()->isOnline())->toBeFalse();

    Event::assertDispatched(UserPresenceUpdated::class, fn ($e) => $e->userId === $agent->id && $e->presence === 'offline');

    // The heartbeat says the dashboard is open, not that they want work.
    $this->postJson('/api/user/heartbeat')->assertNoContent();
    expect($agent->fresh()->isOnline())->toBeFalse();

    $this->getJson('/api/user')->assertJsonPath('data.presence', 'offline');

    $this->putJson('/api/user/presence', ['status' => 'online'])->assertJsonPath('presence', 'online');
    expect($agent->fresh()->isOnline())->toBeTrue();
});

test('signing in again turns the switch back to Online', function () {
    $agent = User::factory()->create([
        'email' => 'presence-'.uniqid().'@example.test',
        'password' => Hash::make('correct-horse-battery'),
    ]);
    $tenant = \App\Models\Tenant::create(['user_id' => $agent->id]);
    $agent->forceFill(['tenant_id' => $tenant->id, 'offline_since' => now()])->save();

    $this->postJson('/api/auth/login', ['email' => $agent->email, 'password' => 'correct-horse-battery'])
        ->assertOk()
        ->assertJsonPath('user.presence', 'online');

    expect($agent->fresh()->offline_since)->toBeNull()
        ->and($agent->fresh()->isOnline())->toBeTrue();
});

test('a Back Office operator looking in does not make the agent online, nor flip their switch', function () {
    $scene = haScene([]);
    $agent = haAgent($scene, online: false);
    $token = $agent->createToken('impersonation', ['*'], now()->addHour())->plainTextToken;

    Auth::forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/user/heartbeat')
        ->assertNoContent();

    expect($agent->fresh()->last_seen_at)->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->putJson('/api/user/presence', ['status' => 'offline'])
        ->assertForbidden()
        ->assertJsonPath('code', 'impersonation_read_only');

    expect($agent->fresh()->offline_since)->toBeNull();
});
