<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Events\AccountDeactivated;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Http\Controllers\Api\ConversationController;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Otp;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use App\Services\Notification\WhatsappAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Deactivating someone who no longer works here: their access ends, their
 * history stays, and their seat frees up.
 */
uses(RefreshDatabase::class);

const DEACT_OWNER_PERMISSIONS = [
    'agents.view', 'agents.create', 'agents.delete', 'agents.deactivate', 'conversations.transfer',
];

function deactOwner(): User
{
    $owner = User::factory()->create(['name' => 'Marina Dona']);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('owner', 'web');
    foreach (DEACT_OWNER_PERMISSIONS as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $owner->assignRole($role);

    return $owner->fresh();
}

function deactConnection(User $owner): Connection
{
    return Connection::create([
        'tenant_id' => $owner->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'Vendas',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);
}

function deactAgent(User $owner, array $connections, string $name, array $permissions = []): User
{
    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id, 'name' => $name]);
    $agent->connections()->syncWithoutDetaching(collect($connections)->pluck('id')->all());

    foreach ($permissions as $permission) {
        $agent->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $agent->fresh();
}

function deactConversation(Connection $connection, ?User $assignee, ConversationStatus $status, string $externalId): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => $externalId,
        'name' => 'Cliente '.$externalId,
        'channel' => $connection->channel,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'user_id' => $assignee?->id,
        'external_id' => $externalId,
        'type' => ConversationType::Private,
        'status' => $status,
    ]);
}

/** A usable plan with this many seats. */
function deactSeats(User $owner, int $seats): void
{
    $plan = Plan::create([
        'name' => 'Seats',
        'slug' => 'seats-'.uniqid(),
        'price_cents' => 9900,
        'currency' => 'BRL',
        'billing_cycle' => 'monthly',
        'features' => ['chat' => true],
        'quotas' => ['max_agents' => $seats],
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $owner->tenant_id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'features_snapshot' => $plan->features,
        'quotas_snapshot' => $plan->quotas,
        'current_period_start' => now()->subDays(3),
        'current_period_end' => now()->addDays(27),
    ]);

    $owner->tenant->update(['current_subscription_id' => $subscription->id]);
    app(SubscriptionGate::class)->forget($owner->tenant->fresh());
}

beforeEach(function () {
    // Only the broadcasts: model events stay real, so ConversationObserver
    // still writes its status note.
    Event::fake([ConversationUpdated::class, MessageReceived::class, AccountDeactivated::class]);
});

test('deactivating someone ends their access and keeps their name on their history', function () {
    $owner = deactOwner();
    $connection = deactConnection($owner);
    $agent = deactAgent($owner, [$connection], 'Bruna');
    $agent->createToken('auth_token');
    $agent->markSeen();

    $open = deactConversation($connection, $agent, ConversationStatus::Active, '5511900000001');
    $resolved = deactConversation($connection, $agent, ConversationStatus::Resolved, '5511900000002');

    $this->actingAs($owner)
        ->postJson("/api/agents/{$agent->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('changed', true)
        ->assertJsonPath('summary.returned_to_queue', 1)
        ->assertJsonPath('data.deactivated_by.name', 'Marina Dona');

    $agent->refresh();

    expect($agent->isDeactivated())->toBeTrue()
        ->and($agent->deactivated_by_user_id)->toBe($owner->id)
        ->and($agent->tokens()->count())->toBe(0)
        ->and($agent->isOnline())->toBeFalse()
        ->and($agent->canAccessConnection($connection))->toBeFalse()
        // The inbox rows they had are kept, for the day they come back.
        ->and($agent->connections()->count())->toBe(1);

    // The customer mid-conversation goes back to the queue…
    expect($open->fresh()->user_id)->toBeNull()
        ->and($open->fresh()->status)->toBe(ConversationStatus::Pending)
        // …and what they already handled still says they handled it.
        ->and($resolved->fresh()->user_id)->toBe($agent->id);

    Event::assertDispatched(AccountDeactivated::class, fn ($event) => $event->userId === $agent->id);
    expect(AuditLog::where('action', 'agent.deactivated')->count())->toBe(1);
});

test('their open conversations can go to a colleague instead', function () {
    $owner = deactOwner();
    $connection = deactConnection($owner);
    $agent = deactAgent($owner, [$connection], 'Bruna');
    $colleague = deactAgent($owner, [$connection], 'Carla');
    $open = deactConversation($connection, $agent, ConversationStatus::Active, '5511900000003');

    $this->actingAs($owner)
        ->postJson("/api/agents/{$agent->id}/deactivate", ['reassign_to' => $colleague->id])
        ->assertOk()
        ->assertJsonPath('summary.transferred', 1);

    expect($open->fresh()->user_id)->toBe($colleague->id)
        ->and($open->fresh()->messages()->where('meta->info->code', ConversationController::INFO_TRANSFERRED)->exists())->toBeTrue();
});

test('nobody who left can receive the conversations', function () {
    $owner = deactOwner();
    $connection = deactConnection($owner);
    $agent = deactAgent($owner, [$connection], 'Bruna');
    $former = deactAgent($owner, [$connection], 'Davi');
    $former->forceFill(['deactivated_at' => now()])->save();
    deactConversation($connection, $agent, ConversationStatus::Active, '5511900000004');

    $this->actingAs($owner)
        ->postJson("/api/agents/{$agent->id}/deactivate", ['reassign_to' => $former->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reassign_to');

    expect($agent->fresh()->isDeactivated())->toBeFalse();
});

test('a second press changes and records nothing', function () {
    $owner = deactOwner();
    $agent = deactAgent($owner, [], 'Bruna');

    $this->actingAs($owner)->postJson("/api/agents/{$agent->id}/deactivate")->assertOk();
    $this->actingAs($owner)
        ->postJson("/api/agents/{$agent->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('changed', false);

    expect(AuditLog::where('action', 'agent.deactivated')->count())->toBe(1);
});

test('the owner cannot be deactivated, and nobody deactivates themselves', function () {
    $owner = deactOwner();
    $supervisor = deactAgent($owner, [], 'Sam', ['agents.deactivate']);

    $this->actingAs($supervisor)
        ->postJson("/api/agents/{$owner->id}/deactivate")
        ->assertForbidden()
        ->assertJsonPath('code', 'agent_protected');

    $this->actingAs($supervisor)
        ->postJson("/api/agents/{$supervisor->id}/deactivate")
        ->assertStatus(422)
        ->assertJsonPath('code', 'cannot_deactivate_self');

    expect($owner->fresh()->isDeactivated())->toBeFalse()
        ->and($supervisor->fresh()->isDeactivated())->toBeFalse();
});

test('without the permission, neither direction is open', function () {
    $owner = deactOwner();
    $plain = deactAgent($owner, [], 'Plain');
    $agent = deactAgent($owner, [], 'Bruna');

    $this->actingAs($plain)->postJson("/api/agents/{$agent->id}/deactivate")->assertForbidden();
    $this->actingAs($plain)->postJson("/api/agents/{$agent->id}/reactivate")->assertForbidden();

    expect($agent->fresh()->isDeactivated())->toBeFalse();
});

test('an agent of another workspace is out of reach', function () {
    $owner = deactOwner();
    $stranger = deactAgent(deactOwner(), [], 'Elsewhere');

    $this->actingAs($owner)->postJson("/api/agents/{$stranger->id}/deactivate")->assertNotFound();

    expect($stranger->fresh()->isDeactivated())->toBeFalse();
});

test('someone who left cannot sign in, nor set a new password', function () {
    $owner = deactOwner();
    $agent = deactAgent($owner, [], 'Bruna');
    // Set directly: signing in as the owner first would leave the test's guard
    // holding them, and the login below must be a stranger's request.
    $agent->forceFill([
        'password' => 'SenhaForte!2026',
        'whatsapp_number' => '5511988887777',
        'deactivated_at' => now(),
        'deactivated_by_user_id' => $owner->id,
    ])->save();

    // The right password is told why.
    $this->postJson('/api/auth/login', ['email' => $agent->email, 'password' => 'SenhaForte!2026'])
        ->assertForbidden()
        ->assertJsonPath('code', 'account_deactivated')
        ->assertJsonMissingPath('access_token');

    // A wrong one learns nothing it would not learn anyway.
    $this->postJson('/api/auth/login', ['email' => $agent->email, 'password' => 'wrong-password'])
        ->assertStatus(401)
        ->assertJsonPath('code', 'login_failed');

    // A reset is answered as for an address with no account: no code issued.
    $this->postJson('/api/auth/password/forgot', ['email' => $agent->email])->assertOk();

    expect(Otp::query()->where('user_id', $agent->id)->exists())->toBeFalse()
        ->and($agent->fresh()->tokens()->count())->toBe(0);
});

test('a session that survived deactivation is refused anyway', function () {
    $owner = deactOwner();
    $agent = deactAgent($owner, [], 'Bruna');
    $token = $agent->createToken('auth_token')->plainTextToken;

    // Closed without going through the service, as if the revoke were missed.
    $agent->forceFill(['deactivated_at' => now()])->save();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/user')
        ->assertUnauthorized();
});

test('nothing is handed to them, and no alert reaches their phone', function () {
    $owner = deactOwner();
    $connection = deactConnection($owner);
    $agent = deactAgent($owner, [$connection], 'Bruna');
    $agent->forceFill([
        'whatsapp_number' => '5511988887777',
        'whatsapp_verified_at' => now(),
        'notification_preferences' => ['whatsapp_alerts' => [WhatsappAlerts::CONNECTION_DOWN => true]],
    ])->save();
    $conversation = deactConversation($connection, $owner, ConversationStatus::Active, '5511900000005');

    expect(WhatsappAlerts::recipients($owner->tenant_id, WhatsappAlerts::CONNECTION_DOWN, $connection)->pluck('id')->all())
        ->toContain($agent->id);

    $this->actingAs($owner)->postJson("/api/agents/{$agent->id}/deactivate")->assertOk();

    $targets = $this->actingAs($owner)
        ->getJson("/api/conversations/{$conversation->id}/transfer-targets")
        ->assertOk()
        ->json('data');

    expect(collect($targets)->pluck('id')->all())->not->toContain($agent->id);

    $this->actingAs($owner)
        ->postJson("/api/conversations/{$conversation->id}/transfer", ['agent_id' => $agent->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'agent_missing_connection_access');

    expect(WhatsappAlerts::recipients($owner->tenant_id, WhatsappAlerts::CONNECTION_DOWN, $connection)->pluck('id')->all())
        ->not->toContain($agent->id);
});

test('a deactivated account frees its seat, and coming back needs one', function () {
    $owner = deactOwner();
    $connection = deactConnection($owner);
    $agent = deactAgent($owner, [$connection], 'Bruna');
    deactSeats($owner, 2);
    config(['services.billing.enforce' => true]);

    $newcomer = fn (string $name) => [
        'name' => $name, 'email' => strtolower($name).'-'.uniqid().'@example.test', 'password' => 'SenhaForte!2026',
    ];

    // Owner + Bruna fill both seats.
    $this->actingAs($owner)->postJson('/api/agents', $newcomer('Carla'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'quota_exceeded');

    $this->actingAs($owner)->postJson("/api/agents/{$agent->id}/deactivate")->assertOk();

    $carla = $this->actingAs($owner)->postJson('/api/agents', $newcomer('Carla'))
        ->assertCreated()
        ->json('data.id');

    // Full again: Bruna cannot come back over the limit.
    $this->actingAs($owner)->postJson("/api/agents/{$agent->id}/reactivate")
        ->assertStatus(422)
        ->assertJsonPath('code', 'quota_exceeded');

    expect($agent->fresh()->isDeactivated())->toBeTrue();

    $this->actingAs($owner)->postJson("/api/agents/{$carla}/deactivate")->assertOk();

    $this->actingAs($owner)->postJson("/api/agents/{$agent->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('changed', true)
        ->assertJsonPath('data.deactivated_at', null);

    $agent->refresh();

    expect($agent->isDeactivated())->toBeFalse()
        ->and($agent->canAccessConnection($connection))->toBeTrue()
        ->and(AuditLog::where('action', 'agent.reactivated')->count())->toBe(1);
});

test('the team list shows who left, when and who closed the account', function () {
    $owner = deactOwner();
    $agent = deactAgent($owner, [], 'Bruna');

    $this->actingAs($owner)->postJson("/api/agents/{$agent->id}/deactivate")->assertOk();

    $row = collect($this->actingAs($owner)->getJson('/api/agents')->assertOk()->json('data'))
        ->firstWhere('id', $agent->id);

    expect($row['deactivated_at'])->not->toBeNull()
        ->and($row['deactivated_by']['name'])->toBe('Marina Dona');
});
