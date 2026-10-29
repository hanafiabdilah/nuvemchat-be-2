<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Gallery\AssetType;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Flow\NodeType;
use App\Models\AiHubProviderCredential;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Flow;
use App\Models\FlowAssistantMessage;
use App\Models\FlowAssistantSetting;
use App\Models\GalleryAsset;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\AiAgentHub\AiAgentHubService;
use App\Services\AiTokens\AiTokenRentalService;
use App\Services\Flow\FlowBlueprint;
use App\Services\FlowAssistant\FlowAssistantConfig;
use App\Services\FlowAssistant\FlowAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreditFixtures;

uses(RefreshDatabase::class);

/**
 * A workspace whose plan includes the assistant. Still to do before it can
 * ask anything: choose a key ({@see provisionAssistant()}).
 */
function assistantUser(bool $withFeature = true): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    // A real plan and subscription rather than an entitlement override:
    // overrides are layered onto a *usable* plan's entitlements and are never
    // reached without one, so a tenant carrying only an override reads as
    // having no features at all.
    $features = $withFeature
        ? ['flow' => true, 'flow_assistant' => true]
        : ['flow' => true];

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-' . uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
        'features' => $features, 'quotas' => [],
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active, 'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990, 'quantity' => 1,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        'quotas_snapshot' => [], 'features_snapshot' => $features,
    ]);
    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    $role = Role::findOrCreate('flow-assistant-' . $tenant->id, 'web');
    $role->givePermissionTo(Permission::findOrCreate('flows.update', 'web'));
    $user->assignRole($role);

    return $user->fresh();
}

function provisionAssistant(User $user, bool $rented = false): FlowAssistantSetting
{
    // Runs go out under the platform's hub token whatever key the workspace
    // chose — Pingly is one tenant of the hub.
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'hub-tenant-token');

    $hubTenant = app(AiAgentHubService::class)->createTenant($user->tenant);

    $credential = AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_provider_credential_id' => 'hub-cred-' . uniqid(),
        'provider' => 'OPENAI',
        'name' => $rented ? 'Pingly — OPENAI (alugado)' : 'Minha chave',
        'status' => 'ACTIVE',
        'ai_token_pool_key_id' => $rented ? CreditFixtures::poolKey()->id : null,
    ]);

    return FlowAssistantSetting::create([
        'tenant_id' => $user->tenant_id,
        'ai_hub_provider_credential_id' => $credential->id,
        'model' => 'gpt-4o',
        'hub_agent_id' => 'hub-agent-1',
        'agent_external_id' => config('app.name') . '_' . $hubTenant->id . '_flow_assistant',
    ]);
}

/** One file in a workspace's media library. */
function galleryAsset(int $tenantId, string $name): GalleryAsset
{
    $extension = pathinfo($name, PATHINFO_EXTENSION) ?: 'png';

    return GalleryAsset::create([
        'tenant_id' => $tenantId,
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'public_filename' => $name,
        'name' => $name,
        'path' => "gallery/{$tenantId}/" . Illuminate\Support\Str::random(12) . ".{$extension}",
        'mime_type' => $extension === 'pdf' ? 'application/pdf' : 'image/png',
        'type' => $extension === 'pdf' ? AssetType::Document : AssetType::Image,
        'size_bytes' => 2048,
        'checksum' => hash('sha256', $name),
    ]);
}

/** The decoded body of one hub run, carrying whatever the model "wrote". */
function hubRunBody(string $message): array
{
    return [
        'id' => 'run-' . uniqid(),
        'status' => 'COMPLETED',
        'model' => 'gpt-4o',
        'output' => ['message' => $message, 'usage' => ['totalTokens' => 900]],
    ];
}

/** The same, as a faked response. */
function hubRun(string $message)
{
    return Http::response(hubRunBody($message));
}

/** The envelope the assistant is told to produce. */
function envelope(?array $flow, string $reply = 'Pronto.'): string
{
    return json_encode(['reply' => $reply, 'flow' => $flow]);
}

/** A minimal flow that passes every rule: start → message → close. */
function validBlueprint(): array
{
    return [
        'name' => 'Atendimento',
        'nodes' => [
            ['key' => '1', 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['key' => '2', 'type' => 'message', 'data' => [
                'messages' => [['message_type' => 'text', 'body' => 'Olá!', 'delay' => 0]],
            ], 'position_x' => 280, 'position_y' => 0],
            ['key' => '3', 'type' => 'status', 'data' => ['value' => 'resolved'], 'position_x' => 560, 'position_y' => 0],
        ],
        'edges' => [
            ['source_key' => '1', 'target_key' => '2', 'condition_value' => null],
            ['source_key' => '2', 'target_key' => '3', 'condition_value' => null],
        ],
    ];
}

// ───────────────────────────── The contract ─────────────────────────────

test('the specification handed to the model is derived from the code, not retyped', function () {
    // The whole reason FlowBlueprint owns both the rules and the prompt: a
    // hand-written spec is correct on the day it is written and silently wrong
    // after the next node type — and the customer sees that as an assistant
    // whose flows will not save.
    $spec = FlowBlueprint::specification();

    foreach (FlowBlueprint::NODE_TYPES as $type) {
        expect($spec)->toContain($type);
    }

    expect($spec)
        ->toContain((string) App\Services\Flow\MessageNodes::MAX_DELAY_SECONDS)
        ->toContain((string) App\Services\Flow\InteractiveNodes::CAROUSEL_MAX_CARDS);
});

test('a condition branch wired with anything but true/false is reported', function () {
    // Saves cleanly and then never takes a branch at runtime — the worst kind
    // of generated output, because it looks finished.
    $problems = FlowBlueprint::structureProblems(
        [
            ['key' => '1', 'type' => 'start'],
            ['key' => '2', 'type' => 'condition'],
            ['key' => '3', 'type' => 'message'],
        ],
        [
            ['source_key' => '1', 'target_key' => '2', 'condition_value' => null],
            ['source_key' => '2', 'target_key' => '3', 'condition_value' => 'sim'],
        ],
    );

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('"sim"')
        ->and($problems[0])->toContain('"true", "false"');
});

test('a node nothing points at is reported', function () {
    $problems = FlowBlueprint::structureProblems(
        [
            ['key' => '1', 'type' => 'start'],
            ['key' => '2', 'type' => 'message'],
            ['key' => '99', 'type' => 'message'],
        ],
        [['source_key' => '1', 'target_key' => '2', 'condition_value' => null]],
    );

    expect($problems)->toHaveCount(1)->and($problems[0])->toContain('"99"');
});

test('a sound flow reports nothing', function () {
    $blueprint = validBlueprint();

    expect(FlowBlueprint::structureProblems($blueprint['nodes'], $blueprint['edges']))->toBe([]);
});

// ────────────────────────────── The service ─────────────────────────────

test('a valid blueprint comes back with its positions intact', function () {
    $user = assistantUser();
    provisionAssistant($user);

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(validBlueprint(), 'Criei um fluxo simples.')),
    ]);

    $result = app(FlowAssistantService::class)->ask($user->tenant, 'Crie um atendimento simples', ['flow' => null]);

    expect($result['flow']['nodes'])->toHaveCount(3)
        ->and($result['reply'])->toBe('Criei um fluxo simples.')
        ->and($result['warnings'])->toBe([]);
});

test('an invalid blueprint is handed back to the model instead of to the builder', function () {
    $user = assistantUser();
    provisionAssistant($user);

    // First answer: a response node with no variable_key — the single most
    // common slip, and one the save endpoint refuses.
    $broken = validBlueprint();
    $broken['nodes'][1] = [
        'key' => '2', 'type' => 'response',
        'data' => ['body' => 'Qual seu nome?', 'message_type' => 'text'],
        'position_x' => 280, 'position_y' => 0,
    ];

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => Http::sequence()
            ->push(hubRunBody(envelope($broken)))
            ->push(hubRunBody(envelope(validBlueprint(), 'Corrigido.'))),
    ]);

    $result = app(FlowAssistantService::class)->ask($user->tenant, 'Pergunte o nome', ['flow' => null]);

    expect($result['flow'])->not->toBeNull()
        ->and($result['warnings'])->toHaveCount(1);

    // The repair turn must actually have carried the problem, or the second
    // answer is a coincidence rather than a fix.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/runs')
        && str_contains($request->body(), 'variable key'));
});

test('a blueprint that will not validate is never handed to the builder', function () {
    $user = assistantUser();
    provisionAssistant($user);

    $broken = validBlueprint();
    $broken['nodes'][1]['type'] = 'response';
    $broken['nodes'][1]['data'] = ['body' => 'Oi', 'message_type' => 'text'];

    // Same broken answer every time — the model refusing to learn.
    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope($broken)),
    ]);

    $result = app(FlowAssistantService::class)->ask($user->tenant, 'Pergunte o nome', ['flow' => null]);

    // Prose, no flow. Putting a canvas full of nodes in front of someone and
    // then refusing to save it is worse than saying it did not work.
    expect($result['flow'])->toBeNull()
        ->and($result['reply'])->toContain('não apliquei');

    // Two repairs and no more: a model still wrong after the second is wrong
    // about something the errors are not telling it, and a third attempt
    // spends the customer's time to arrive at the same place. Counted on the
    // run endpoint alone — the agent PATCH beside it is prompt housekeeping.
    $runs = 0;
    Http::assertSent(function ($request) use (&$runs) {
        $runs += str_ends_with($request->url(), '/runs') ? 1 : 0;

        return true;
    });

    expect($runs)->toBe(3);
});

test('a tag from another workspace is refused, not saved', function () {
    $user = assistantUser();
    provisionAssistant($user);

    $stranger = User::factory()->create();
    $otherTenant = Tenant::create(['user_id' => $stranger->id]);
    $foreignTag = Tag::create(['tenant_id' => $otherTenant->id, 'name' => 'VIP', 'color' => '#fff']);

    // The foreign tag's real id, not a made-up one. Before this was scoped, the
    // rule was a bare `exists:tags,id` and nothing scoped it at runtime either,
    // so this blueprint saved and put another workspace's label on this
    // workspace's conversations.
    $blueprint = validBlueprint();
    $blueprint['nodes'][1] = [
        'key' => '2', 'type' => 'tagging',
        'data' => ['action' => 'add', 'target' => 'conversation', 'tags' => [$foreignTag->id]],
        'position_x' => 280, 'position_y' => 0,
    ];

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope($blueprint)),
    ]);

    $this->actingAs($user, 'sanctum');
    $result = app(FlowAssistantService::class)->ask($user->tenant, 'Marque como VIP', ['flow' => null]);

    expect($result['flow'])->toBeNull();
});

test('prose with no flow is a legitimate turn, not a parse failure', function () {
    $user = assistantUser();
    provisionAssistant($user);

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(null, 'Um nó de condição divide o fluxo em dois caminhos.')),
    ]);

    $result = app(FlowAssistantService::class)->ask($user->tenant, 'O que faz um nó de condição?', ['flow' => null]);

    expect($result['flow'])->toBeNull()
        ->and($result['reply'])->toContain('condição');
});

test('a fenced envelope with a sentence in front of it is still read', function () {
    $user = assistantUser();
    provisionAssistant($user);

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun("Claro! Aqui está:\n```json\n" . envelope(validBlueprint()) . "\n```"),
    ]);

    $result = app(FlowAssistantService::class)->ask($user->tenant, 'Crie um fluxo', ['flow' => null]);

    expect($result['flow']['nodes'])->toHaveCount(3);
});

// ────────────────────────── Talking to the hub ──────────────────────────

test('a hub validation failure keeps its sentence instead of arriving as a JSON blob', function () {
    $user = assistantUser();
    provisionAssistant($user);

    // The shape a NestJS hub answers with. Handing the raw body to UpstreamError
    // matched nothing in its dictionary, so every failure of this feature came
    // out as the generic "O serviço de IA está indisponível" with the actual
    // reason visible nowhere — which is exactly how it was first reported.
    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => Http::response([
            'message' => ['channel must be one of the following values: whatsapp'],
            'statusCode' => 400,
        ], 400),
    ]);

    try {
        app(FlowAssistantService::class)->ask($user->tenant, 'Crie um fluxo', ['flow' => null]);
        $this->fail('the hub failure should have surfaced');
    } catch (App\Exceptions\UpstreamServiceException $e) {
        // The customer still gets our copy…
        expect($e->getMessage())->not->toContain('statusCode');
        // …but the operator's half carries the hub's own words.
        expect($e->rawMessage)->toBe('channel must be one of the following values: whatsapp');
    }
});

test('the run says whatsapp, the one channel value every working agent already sends', function () {
    $user = assistantUser();
    provisionAssistant($user);

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(null, 'ok')),
    ]);

    app(FlowAssistantService::class)->ask($user->tenant, 'Oi', ['flow' => null]);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/runs')) return false;

        return $request->data()['conversation']['channel'] === 'whatsapp';
    });
});

test('an agent the hub already holds is adopted rather than deadlocking on 409', function () {
    // `externalId` is unique at the hub. Once the local row holding the agent's
    // id is lost, every re-create answers 409 and the workspace could never
    // choose a key again from any screen.
    $user = assistantUser();
    $setting = provisionAssistant($user);
    $setting->update(['hub_agent_id' => null]);

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'existing-agent']),
        '*/agents' => function ($request) use ($setting) {
            return $request->method() === 'POST'
                ? Http::response(['message' => 'externalId already in use'], 409)
                : Http::response([['id' => 'existing-agent', 'externalId' => $setting->agent_external_id]]);
        },
    ]);

    $configured = app(FlowAssistantService::class)->configure($user->tenant, $setting->providerCredential, 'gpt-4o');

    expect($configured->hub_agent_id)->toBe('existing-agent');
});

// ───────────────────────────── The endpoints ────────────────────────────

test('the assistant is refused to a plan that does not include it', function () {
    config()->set('services.billing.enforce', true);

    $user = assistantUser(withFeature: false);
    provisionAssistant($user);
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Fluxo']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", ['message' => 'Crie um fluxo'])
        ->assertForbidden()
        ->assertJsonPath('code', 'feature_not_in_plan');
});

test('the assistant answers a workspace whose plan includes it', function () {
    config()->set('services.billing.enforce', true);

    $user = assistantUser();
    provisionAssistant($user);
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Fluxo']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(validBlueprint(), 'Feito.')),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", ['message' => 'Crie um atendimento'])
        ->assertOk()
        ->assertJsonPath('data.reply', 'Feito.')
        ->assertJsonCount(3, 'data.flow.nodes');
});

test('the workspace real tags and agents reach the model, and nothing else does', function () {
    $user = assistantUser();
    provisionAssistant($user);

    $tag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Urgente', 'color' => '#f00']);
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Fluxo']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $stranger = User::factory()->create();
    $otherTenant = Tenant::create(['user_id' => $stranger->id]);
    Tag::create(['tenant_id' => $otherTenant->id, 'name' => 'SegredoAlheio', 'color' => '#00f']);

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(null, 'ok')),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", ['message' => 'Marque como urgente'])
        ->assertOk();

    Http::assertSent(function ($request) use ($tag) {
        if (! str_contains($request->url(), '/runs')) {
            return false;
        }

        return str_contains($request->body(), 'Urgente')
            && str_contains($request->body(), (string) $tag->id)
            && ! str_contains($request->body(), 'SegredoAlheio');
    });
});

// ──────────────────── A thread that belongs to the flow ─────────────────

test('the thread is readable by another agent on the same flow', function () {
    // The whole point of persisting it. The transcript used to live in one tab
    // and be posted back with every turn, so closing the panel lost it and a
    // colleague opening the same flow saw an empty box with no idea what had
    // been asked or why the flow looks the way it does.
    $owner = assistantUser();
    provisionAssistant($owner);

    $flow = Flow::create(['tenant_id' => $owner->tenant_id, 'name' => 'Fluxo']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(validBlueprint(), 'Montei o fluxo.')),
    ]);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", ['message' => 'Crie um atendimento'])
        ->assertOk();

    // A second agent in the same workspace, who was not there when it was asked.
    $colleague = User::factory()->create(['tenant_id' => $owner->tenant_id]);
    $colleague->assignRole('flow-assistant-' . $owner->tenant_id);

    $this->actingAs($colleague->fresh(), 'sanctum')
        ->getJson("/api/flows/{$flow->id}/assistant/messages")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.role', 'user')
        ->assertJsonPath('data.0.author', $owner->name)
        ->assertJsonPath('data.1.content', 'Montei o fluxo.')
        // The blueprint travels with the turn, so an old proposal can go back
        // on the canvas without paying for a second run.
        ->assertJsonCount(3, 'data.1.flow.nodes');
});

test('another workspace cannot read the thread', function () {
    $owner = assistantUser();
    $flow = Flow::create(['tenant_id' => $owner->tenant_id, 'name' => 'Fluxo']);

    FlowAssistantMessage::create([
        'flow_id' => $flow->id, 'tenant_id' => $owner->tenant_id,
        'user_id' => $owner->id, 'role' => 'user', 'content' => 'segredo',
    ]);

    $stranger = assistantUser();

    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/flows/{$flow->id}/assistant/messages")
        ->assertNotFound();
});

test('the model is told the earlier turns, without the blueprints', function () {
    $user = assistantUser();
    provisionAssistant($user);

    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Fluxo']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    FlowAssistantMessage::create([
        'flow_id' => $flow->id, 'tenant_id' => $user->tenant_id, 'user_id' => $user->id,
        'role' => 'user', 'content' => 'Quero um menu de pizzaria',
    ]);
    FlowAssistantMessage::create([
        'flow_id' => $flow->id, 'tenant_id' => $user->tenant_id, 'user_id' => null,
        'role' => 'assistant', 'content' => 'Feito.', 'blueprint' => validBlueprint(),
    ]);

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(null, 'ok')),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", ['message' => 'Adicione o encerramento'])
        ->assertOk();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/runs')) return false;

        $content = $request->data()['message']['content'];

        // The words carry the thread; a past blueprint is enormous, stale the
        // moment the flow changes, and the current flow is sent separately.
        return str_contains($content, 'Quero um menu de pizzaria')
            && str_contains($content, 'Earlier in this conversation')
            && ! str_contains($content, 'position_x": 280');
    });
});

// ─────────────────────────── Media from the library ─────────────────────

test('picked library files reach the model as URLs it is told to copy exactly', function () {
    $user = assistantUser();
    provisionAssistant($user);

    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Fluxo']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $asset = galleryAsset($user->tenant_id, 'menu-2026.png');

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(null, 'ok')),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", [
            'message' => 'Mande esta imagem na saudação',
            'gallery_asset_ids' => [$asset->id],
        ])
        ->assertOk();

    Http::assertSent(function ($request) use ($asset) {
        if (! str_ends_with($request->url(), '/runs')) return false;

        $content = $request->data()['message']['content'];

        return str_contains($content, 'menu-2026.png')
            && str_contains($content, $asset->publicUrl())
            && str_contains($content, 'attachment_url');
    });
});

test('a library file from another workspace is silently not offered to the model', function () {
    // Resolved from ids here, so a picker that has gone stale — or a request
    // built by hand — cannot put a stranger's file into a flow that will send
    // it to customers for months.
    $user = assistantUser();
    provisionAssistant($user);

    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Fluxo']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $stranger = assistantUser();
    $foreign = galleryAsset($stranger->tenant_id, 'contrato-alheio.pdf');

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(null, 'ok')),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", [
            'message' => 'Use este arquivo',
            'gallery_asset_ids' => [$foreign->id],
        ])
        ->assertOk();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/runs')) return false;

        return ! str_contains($request->data()['message']['content'], 'contrato-alheio.pdf');
    });
});

test('the specification tells the model how to call an API with GET and POST', function () {
    // The answer to "can it build a flow that calls my endpoint?" — the node
    // has always existed, but the prompt has to say enough for the model to
    // wire it: a verb, a JSON body as a string, mapped variables, both branches.
    $spec = FlowBlueprint::specification();

    expect($spec)
        ->toContain('http_request')
        ->toContain('"method": "GET"')
        ->toContain('"method": "POST"')
        ->toContain('response_mappings')
        ->toContain('Content-Type')
        // Both outputs wired: an unwired error branch is a flow that goes
        // silent when the endpoint is down.
        ->toContain('"success" and "error"');
});

test('the assistant reports itself not set up until the workspace chooses a key', function () {
    $user = assistantUser();
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'hub-tenant-token');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/flows/assistant/status')
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.configured', false);

    provisionAssistant($user);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/flows/assistant/status')
        ->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.mode', 'own')
        ->assertJsonPath('data.model', 'gpt-4o');
});

test('the assistant is unavailable without the platform hub token', function () {
    // Nothing a customer can do about that one, so the builder hides the
    // button rather than asking for a key that would not help.
    $user = assistantUser();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/flows/assistant/status')
        ->assertOk()
        ->assertJsonPath('data.available', false);
});

test('the composer ceiling is served, not left for the browser to know', function () {
    // The number backing the validation rule below. A copy written into
    // TypeScript drifts the day this one moves, and the drift shows up as a
    // message the box accepted and the server refused.
    $user = assistantUser();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/flows/assistant/status')
        ->assertOk()
        ->assertJsonPath('data.max_message_chars', FlowAssistantConfig::MAX_MESSAGE_CHARS);
});

test('a long pasted prompt is accepted', function () {
    // The ceiling used to be 4000, tuned for someone typing a sentence. People
    // paste prompts another AI wrote for them, and those run long — so the
    // limit was refusing the requests this feature is best at, after they had
    // already been written.
    $user = assistantUser();
    provisionAssistant($user);

    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Fluxo']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(validBlueprint(), 'Pronto.')),
    ]);

    $message = str_repeat('Descreva o atendimento da pizzaria em detalhes. ', 200);

    expect(mb_strlen($message))->toBeGreaterThan(4000);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", ['message' => $message])
        ->assertOk();

    // Whole, not clipped: a request that arrives missing its end is answered
    // as if the person had asked for something else.
    Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/runs')
        || str_contains($request->data()['message']['content'], $message));
});

test('a message past the ceiling is refused before the model is called', function () {
    // Still a ceiling, and it still has to bite before anything is spent: the
    // whole message is replayed into the prompt, and a paste with no upper
    // bound is a prompt with no upper bound.
    $user = assistantUser();
    provisionAssistant($user);

    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Fluxo']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    Http::fake(['*' => Http::response([])]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", [
            'message' => str_repeat('a', FlowAssistantConfig::MAX_MESSAGE_CHARS + 1),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');

    Http::assertNothingSent();

    // And nothing landed in the shared transcript — a refused turn is not part
    // of the reasoning a colleague reads later.
    expect(FlowAssistantMessage::where('flow_id', $flow->id)->count())->toBe(0);
});

// ──────────────────────── Whose key the turn runs on ─────────────────────

/** A flow with only its start node, for the endpoint tests. */
function assistantFlow(User $user): Flow
{
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Fluxo']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    return $flow;
}

test('a turn without a key is refused with the setup code, before the hub and the transcript', function () {
    // The code is what the panel switches into its key setup on — and it has
    // to arrive as a status, before the stream starts, or there is none left.
    $user = assistantUser();
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'hub-tenant-token');
    $flow = assistantFlow($user);

    Http::fake(['*' => Http::response([])]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", ['message' => 'Crie um fluxo'])
        ->assertStatus(409)
        ->assertJsonPath('code', FlowAssistantService::SETUP_REQUIRED);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant/stream", ['message' => 'Crie um fluxo'])
        ->assertStatus(409)
        ->assertJsonPath('code', FlowAssistantService::SETUP_REQUIRED);

    Http::assertNothingSent();
    expect(FlowAssistantMessage::where('flow_id', $flow->id)->count())->toBe(0);
});

test('a key that is deleted puts the assistant back to asking for one', function () {
    $user = assistantUser();
    $setting = provisionAssistant($user);

    $setting->providerCredential->delete();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/flows/assistant/status')
        ->assertJsonPath('data.configured', false);
});

test('choosing one of the workspace keys builds the workspace its own agent on it', function () {
    $user = assistantUser();
    $existing = provisionAssistant($user);
    $credential = $existing->providerCredential;
    $existing->delete();

    Http::fake([
        '*/agents' => Http::response(['id' => 'agent-new', 'externalId' => 'Pingly_scoped_flow_assistant'], 201),
    ]);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/flows/assistant/settings', [
            'mode' => 'own',
            'provider_credential_id' => $credential->id,
            'model' => 'gpt-4o-mini',
        ])
        ->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.mode', 'own')
        ->assertJsonPath('data.model', 'gpt-4o-mini');

    // The hub's id for the credential — never the local one, never a key —
    // and an external id scoped to this workspace, so no other workspace's
    // assistant can collide with it.
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/agents')
        && $request['providerCredentialId'] === $credential->hub_provider_credential_id
        && $request['model'] === 'gpt-4o-mini'
        && str_contains($request['externalId'], '_' . $credential->ai_hub_tenant_id . '_flow_assistant'));

    // What runs is the external id the hub answered with.
    expect(FlowAssistantSetting::where('tenant_id', $user->tenant_id)->value('agent_external_id'))
        ->toBe('Pingly_scoped_flow_assistant');
});

test('a key pasted in the setup is registered and the assistant runs on it', function () {
    $user = assistantUser();
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'hub-tenant-token');

    Http::fake([
        '*/provider-credentials' => Http::response(['id' => 'hub-pasted', 'provider' => 'OPENAI', 'name' => 'Chave do assistente', 'keyPreview' => 'sk-...0123', 'status' => 'ACTIVE'], 201),
        '*/agents' => Http::response(['id' => 'agent-new'], 201),
    ]);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/flows/assistant/settings', [
            'mode' => 'own',
            'provider' => 'openai',
            'api_key' => 'sk-test-0123',
            'name' => 'Chave do assistente',
            'model' => 'gpt-4o',
        ])
        ->assertOk()
        ->assertJsonPath('data.credential.name', 'Chave do assistente')
        ->assertJsonPath('data.credential.is_rented', false);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/provider-credentials')
        && $request['apiKey'] === 'sk-test-0123'
        && $request['provider'] === 'OPENAI');

    $credential = AiHubProviderCredential::where('hub_provider_credential_id', 'hub-pasted')->firstOrFail();

    expect($credential->isRented())->toBeFalse()
        ->and(FlowAssistantSetting::where('tenant_id', $user->tenant_id)->value('ai_hub_provider_credential_id'))->toBe($credential->id);
});

test('another workspace key cannot be chosen', function () {
    $user = assistantUser();
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'hub-tenant-token');

    $stranger = assistantUser();
    $foreign = provisionAssistant($stranger)->providerCredential;

    Http::fake(['*' => Http::response([])]);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/flows/assistant/settings', [
            'mode' => 'own', 'provider_credential_id' => $foreign->id, 'model' => 'gpt-4o',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('provider_credential_id');

    Http::assertNothingSent();
});

test('the setup lists the workspace own agent keys, and nothing else', function () {
    $user = assistantUser();
    $setting = provisionAssistant($user);
    $hubTenantId = $setting->providerCredential->ai_hub_tenant_id;

    AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $hubTenantId, 'hub_provider_credential_id' => 'hub-voice',
        'provider' => 'ELEVENLABS', 'name' => 'Voz', 'status' => 'ACTIVE',
    ]);
    AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $hubTenantId, 'hub_provider_credential_id' => 'hub-rented',
        'provider' => 'OPENAI', 'name' => 'Alugado', 'status' => 'ACTIVE',
        'ai_token_pool_key_id' => CreditFixtures::poolKey()->id,
    ]);
    provisionAssistant(assistantUser());

    $names = collect($this->actingAs($user, 'sanctum')
        ->getJson('/api/flows/assistant/settings')
        ->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.default_model', FlowAssistantConfig::DEFAULT_MODEL)
        ->json('data.credentials'))->pluck('name')->all();

    // Rented keys are the other mode; ElevenLabs cannot be an agent's brain.
    expect($names)->toBe(['Minha chave']);
});

test('renting from the setup rents a platform key and puts the assistant on it', function () {
    $user = assistantUser();
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'hub-tenant-token');
    CreditFixtures::poolKey();

    $minted = null;
    Http::fake(function ($request) use (&$minted) {
        if (str_ends_with($request->url(), '/provider-credentials') && $request->method() === 'POST') {
            $minted = 'hub-rented-' . uniqid();

            return Http::response(['id' => $minted, 'provider' => 'OPENAI', 'name' => 'rented', 'status' => 'ACTIVE'], 201);
        }

        if (str_ends_with($request->url(), '/provider-credentials')) {
            return Http::response($minted ? [['id' => $minted, 'provider' => 'OPENAI', 'status' => 'ACTIVE']] : []);
        }

        return Http::response(['id' => 'agent-rented'], 201);
    });

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/flows/assistant/settings', ['mode' => 'rented', 'provider' => 'OPENAI', 'model' => 'gpt-4o'])
        ->assertOk()
        ->assertJsonPath('data.mode', 'rented')
        ->assertJsonPath('data.credential.is_rented', true);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/agents')
        && $request['providerCredentialId'] === $minted);
});

test('each run on a rented key is charged to the balance, and a run on an own key is not', function () {
    $user = assistantUser();
    provisionAssistant($user, rented: true);
    app(App\Services\Credits\CreditService::class)->adjust($user->tenant, 5000, 'test');

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => Http::response([
            'id' => 'run-paid', 'status' => 'COMPLETED', 'provider' => 'OPENAI', 'model' => 'gpt-4o',
            'providerCostUsd' => 0.02, 'output' => ['message' => envelope(null, 'ok')],
        ]),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/flows/' . assistantFlow($user)->id . '/assistant', ['message' => 'Oi'])
        ->assertOk();

    $charge = CreditTransaction::where('reference', 'flow-assistant:run-paid')->firstOrFail();

    expect($charge->amount_cents)->toBeLessThan(0)
        ->and(CreditWallet::where('tenant_id', $user->tenant_id)->value('balance_cents'))->toBeLessThan(5000);

    // Same turn on a workspace's own key: it pays its provider, not us.
    $own = assistantUser();
    provisionAssistant($own);

    $this->actingAs($own, 'sanctum')
        ->postJson('/api/flows/' . assistantFlow($own)->id . '/assistant', ['message' => 'Oi'])
        ->assertOk();

    expect(CreditTransaction::where('tenant_id', $own->tenant_id)->count())->toBe(0);
});

test('a rented key with an empty balance is refused before the hub is called', function () {
    config()->set('services.billing.enforce', true);

    $user = assistantUser();
    provisionAssistant($user, rented: true);
    $flow = assistantFlow($user);

    Http::fake(['*' => Http::response([])]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant/stream", ['message' => 'Oi'])
        ->assertStatus(402)
        ->assertJsonPath('code', 'credit_exhausted');

    Http::assertNothingSent();
});

test('a hub that lost the workspace agent gets it back, and the turn still runs', function () {
    $user = assistantUser();
    provisionAssistant($user);

    Http::fake([
        '*/agents/*' => Http::response(['message' => 'Agent not found'], 404),
        '*/agents' => Http::response(['id' => 'agent-rebuilt'], 201),
        '*/runs' => Http::sequence()
            ->push(['message' => 'Agent not found'], 404)
            ->push(hubRunBody(envelope(null, 'De volta.'))),
    ]);

    $result = app(FlowAssistantService::class)->ask($user->tenant, 'Oi', ['flow' => null]);

    expect($result['reply'])->toBe('De volta.')
        ->and(FlowAssistantSetting::where('tenant_id', $user->tenant_id)->value('hub_agent_id'))->toBe('agent-rebuilt');
});

test('a rented key the assistant runs on cannot be given back', function () {
    // Releasing it would leave the assistant's agent pointing at a credential
    // the hub no longer has, and the failure would surface far from the button.
    $user = assistantUser();
    $credential = provisionAssistant($user, rented: true)->providerCredential;

    Http::fake(['*' => Http::response([])]);

    expect(fn () => app(AiTokenRentalService::class)->release($credential))
        ->toThrow(HttpException::class);

    expect($credential->fresh())->not->toBeNull();
});
