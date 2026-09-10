<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Gallery\AssetType;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Flow\NodeType;
use App\Models\Flow;
use App\Models\FlowAssistantMessage;
use App\Models\GalleryAsset;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\Flow\FlowBlueprint;
use App\Services\FlowAssistant\FlowAssistantConfig;
use App\Services\FlowAssistant\FlowAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * A workspace whose plan includes the assistant, with the platform side
 * provisioned. Both halves are needed: the plan feature is what lets the
 * request through the middleware, the settings are what let the service run.
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

function provisionAssistant(): void
{
    // The platform's key at the hub — the assistant rides the same tenant
    // token as every other hub call this application makes.
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'hub-tenant-token');

    Setting::set(FlowAssistantConfig::KEY_ENABLED, '1');
    Setting::set(FlowAssistantConfig::KEY_API_KEY, 'sk-platform-key');
    Setting::set(FlowAssistantConfig::KEY_AGENT_EXTERNAL_ID, FlowAssistantConfig::AGENT_EXTERNAL_ID);
    Setting::set(FlowAssistantConfig::KEY_HUB_AGENT_ID, 'hub-agent-1');
    Setting::set(FlowAssistantConfig::KEY_HUB_CREDENTIAL_ID, 'hub-cred-1');
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
                'wait_for_reply' => false,
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
    assistantUser();
    provisionAssistant();

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(validBlueprint(), 'Criei um fluxo simples.')),
    ]);

    $result = app(FlowAssistantService::class)->ask('Crie um atendimento simples', ['flow' => null]);

    expect($result['flow']['nodes'])->toHaveCount(3)
        ->and($result['reply'])->toBe('Criei um fluxo simples.')
        ->and($result['warnings'])->toBe([]);
});

test('an invalid blueprint is handed back to the model instead of to the builder', function () {
    assistantUser();
    provisionAssistant();

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

    $result = app(FlowAssistantService::class)->ask('Pergunte o nome', ['flow' => null]);

    expect($result['flow'])->not->toBeNull()
        ->and($result['warnings'])->toHaveCount(1);

    // The repair turn must actually have carried the problem, or the second
    // answer is a coincidence rather than a fix.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/runs')
        && str_contains($request->body(), 'variable key'));
});

test('a blueprint that will not validate is never handed to the builder', function () {
    assistantUser();
    provisionAssistant();

    $broken = validBlueprint();
    $broken['nodes'][1]['type'] = 'response';
    $broken['nodes'][1]['data'] = ['body' => 'Oi', 'message_type' => 'text'];

    // Same broken answer every time — the model refusing to learn.
    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope($broken)),
    ]);

    $result = app(FlowAssistantService::class)->ask('Pergunte o nome', ['flow' => null]);

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
    provisionAssistant();

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
    $result = app(FlowAssistantService::class)->ask('Marque como VIP', ['flow' => null]);

    expect($result['flow'])->toBeNull();
});

test('prose with no flow is a legitimate turn, not a parse failure', function () {
    assistantUser();
    provisionAssistant();

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(null, 'Um nó de condição divide o fluxo em dois caminhos.')),
    ]);

    $result = app(FlowAssistantService::class)->ask('O que faz um nó de condição?', ['flow' => null]);

    expect($result['flow'])->toBeNull()
        ->and($result['reply'])->toContain('condição');
});

test('a fenced envelope with a sentence in front of it is still read', function () {
    assistantUser();
    provisionAssistant();

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun("Claro! Aqui está:\n```json\n" . envelope(validBlueprint()) . "\n```"),
    ]);

    $result = app(FlowAssistantService::class)->ask('Crie um fluxo', ['flow' => null]);

    expect($result['flow']['nodes'])->toHaveCount(3);
});

// ────────────────────────── Talking to the hub ──────────────────────────

test('a hub validation failure keeps its sentence instead of arriving as a JSON blob', function () {
    assistantUser();
    provisionAssistant();

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
        app(FlowAssistantService::class)->ask('Crie um fluxo', ['flow' => null]);
        $this->fail('the hub failure should have surfaced');
    } catch (App\Exceptions\UpstreamServiceException $e) {
        // The customer still gets our copy…
        expect($e->getMessage())->not->toContain('statusCode');
        // …but the operator's half carries the hub's own words.
        expect($e->rawMessage)->toBe('channel must be one of the following values: whatsapp');
    }
});

test('the run says whatsapp, the one channel value every working agent already sends', function () {
    assistantUser();
    provisionAssistant();

    Http::fake([
        '*/agents/*' => Http::response(['id' => 'hub-agent-1']),
        '*/runs' => hubRun(envelope(null, 'ok')),
    ]);

    app(FlowAssistantService::class)->ask('Oi', ['flow' => null]);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/runs')) return false;

        return $request->data()['conversation']['channel'] === 'whatsapp';
    });
});

test('a credential the hub already holds is adopted rather than deadlocking on 409', function () {
    // The hub uniques a credential on (tenant, provider, name). Once the local
    // settings row holding its id is lost, every re-create answers 409 and
    // provisioning can never finish again from any screen — the same dead end
    // AiTokenRentalService::rent() had to grow an adoption path for.
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'hub-tenant-token');

    Http::fake([
        '*/provider-credentials/*' => Http::response(['id' => 'existing-cred']),
        '*/provider-credentials' => Http::sequence()
            ->push(['message' => 'name already in use'], 409)
            ->push([['id' => 'existing-cred', 'name' => 'Pingly platform — flow assistant']]),
        '*/agents/*' => Http::response(['id' => 'hub-agent-1', 'externalId' => 'platform_flow_assistant']),
        '*/agents' => Http::response(['id' => 'hub-agent-1', 'externalId' => 'platform_flow_assistant']),
    ]);

    $result = app(FlowAssistantService::class)->provision('sk-platform-key', 'gpt-4o');

    expect($result['credential_id'])->toBe('existing-cred')
        ->and(FlowAssistantConfig::hubCredentialId())->toBe('existing-cred');
});

// ───────────────────────────── The endpoints ────────────────────────────

test('the assistant is refused to a plan that does not include it', function () {
    config()->set('services.billing.enforce', true);

    $user = assistantUser(withFeature: false);
    provisionAssistant();
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Fluxo']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/flows/{$flow->id}/assistant", ['message' => 'Crie um fluxo'])
        ->assertForbidden()
        ->assertJsonPath('code', 'feature_not_in_plan');
});

test('the assistant answers a workspace whose plan includes it', function () {
    config()->set('services.billing.enforce', true);

    $user = assistantUser();
    provisionAssistant();
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
    provisionAssistant();

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
    provisionAssistant();

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
    provisionAssistant();

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
    provisionAssistant();

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
    provisionAssistant();

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

test('the assistant reports itself unavailable until the platform provisions it', function () {
    $user = assistantUser();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/flows/assistant/status')
        ->assertOk()
        ->assertJsonPath('data.available', false);
});
