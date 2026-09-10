<?php

use App\Enums\Flow\NodeType;
use App\Enums\Lead\LeadStatus;
use App\Events\LeadUpdated;
use App\Models\Conversation;
use App\Models\FlowState;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\LeadNodes;
use App\Services\Lead\PipelineProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    // Only the broadcast: a bare Event::fake() would also silence the model
    // events, and ConversationObserver::created is what opens the automatic
    // lead these tests build on.
    Event::fake([LeadUpdated::class]);
});

/**
 * The default funnel, by stage name: Novo contato, Qualificação, Proposta,
 * Negociação, Cliente (won), Perdido (lost).
 *
 * @return array<string, LeadStage>
 */
function leadNodeStages(Tenant $tenant): array
{
    return app(PipelineProvisioner::class)
        ->ensureDefault($tenant->id)
        ->stages()
        ->get()
        ->keyBy('name')
        ->all();
}

/**
 * start → lead → "Obrigado!".
 *
 * The trailing message is how every test checks the node never strands the
 * customer, whatever it did to the funnel.
 *
 * @param  callable(array<string, LeadStage>, Tenant): array<string, mixed>  $data
 * @return array{0: Conversation, 1: Tenant, 2: array<string, LeadStage>}
 */
function leadNodeFixture(callable $data, bool $autoCreate = true): array
{
    $tenant = Fx::tenant();

    if (! $autoCreate) {
        $tenant->forceFill(['lead_settings' => ['auto_create' => false]])->save();
    }

    $stages = leadNodeStages($tenant);
    $flow = Fx::flow($tenant);

    $lead = Fx::node($flow, NodeType::Lead, $data($stages, $tenant));
    $after = Fx::say($flow, 'Obrigado!');

    Fx::edge(Fx::start($flow), $lead);
    Fx::edge($lead, $after);

    return [Fx::conversation($tenant, $flow), $tenant, $stages];
}

test('opens a lead for a contact without one, even with automatic creation off', function () {
    [$conversation, , $stages] = leadNodeFixture(fn ($s) => ['stage_id' => $s['Qualificação']->id], autoCreate: false);

    expect(Lead::count())->toBe(0);

    (new FlowExecutor)->startFlow($conversation);

    $lead = Lead::sole();

    expect($lead->stage_id)->toBe($stages['Qualificação']->id)
        ->and($conversation->fresh()->lead_id)->toBe($lead->id)
        // The birth and the move are both on the record, and neither names a
        // person: the flow did it.
        ->and($lead->stageEvents()->pluck('to_stage_name')->all())->toBe(['Novo contato', 'Qualificação'])
        ->and($lead->stageEvents()->whereNotNull('user_id')->count())->toBe(0)
        ->and(Fx::sentTexts($conversation))->toBe(['Obrigado!']);

    Event::assertDispatched(LeadUpdated::class, fn (LeadUpdated $event) => $event->lead->is($lead) && $event->moved);
});

test('moves the lead the contact already has, and says so in the thread', function () {
    [$conversation, , $stages] = leadNodeFixture(fn ($s) => ['stage_id' => $s['Proposta']->id]);

    // Opened automatically when the conversation was created.
    $lead = Lead::sole();
    expect($lead->stage_id)->toBe($stages['Novo contato']->id);

    (new FlowExecutor)->startFlow($conversation);

    expect(Lead::count())->toBe(1)
        ->and($lead->fresh()->stage_id)->toBe($stages['Proposta']->id);

    $note = Fx::notes($conversation, LeadNodes::INFO_STAGE_CHANGED)->sole();
    expect($note->meta['info']['params']['stage'])->toBe('Proposta');
});

test('never drags a lead back to an earlier stage', function () {
    [$conversation, , $stages] = leadNodeFixture(fn ($s) => ['stage_id' => $s['Qualificação']->id]);

    // A returning customer an agent already took to Negociação.
    $lead = Lead::sole();
    $lead->moveToStage($stages['Negociação']);

    (new FlowExecutor)->startFlow($conversation);

    expect($lead->fresh()->stage_id)->toBe($stages['Negociação']->id)
        ->and(Fx::notes($conversation, LeadNodes::INFO_STAGE_CHANGED))->toHaveCount(0)
        ->and(Fx::sentTexts($conversation))->toBe(['Obrigado!']);
});

test('with only_forward off, the author may move a lead back', function () {
    [$conversation, , $stages] = leadNodeFixture(fn ($s) => [
        'stage_id' => $s['Qualificação']->id,
        'only_forward' => false,
    ]);

    $lead = Lead::sole();
    $lead->moveToStage($stages['Negociação']);

    (new FlowExecutor)->startFlow($conversation);

    expect($lead->fresh()->stage_id)->toBe($stages['Qualificação']->id);
});

test('a won stage closes the lead, with the title and value the flow filled in', function () {
    [$conversation, , $stages] = leadNodeFixture(fn ($s) => [
        'stage_id' => $s['Cliente']->id,
        'title' => 'Pedido de {{contact.name}}',
        'value' => 'R$ 1.500,00',
    ]);

    (new FlowExecutor)->startFlow($conversation);

    $lead = Lead::sole();

    expect($lead->status)->toBe(LeadStatus::Won)
        ->and($lead->closed_at)->not->toBeNull()
        ->and((float) $lead->value)->toBe(1500.0)
        ->and($lead->title)->toBe('Pedido de Maria Souza');

    $state = FlowState::where('conversation_id', $conversation->id)->sole()->state_data;

    expect($state['lead_id'])->toBe($lead->id)
        ->and($state['lead_stage'])->toBe('Cliente')
        ->and($state['lead_status'])->toBe('won');
});

test('a lost stage records the reason', function () {
    [$conversation, , $stages] = leadNodeFixture(fn ($s) => [
        'stage_id' => $s['Perdido']->id,
        'lost_reason' => 'Sem orçamento',
    ]);

    (new FlowExecutor)->startFlow($conversation);

    $lead = Lead::sole();

    expect($lead->status)->toBe(LeadStatus::Lost)
        ->and($lead->lost_reason)->toBe('Sem orçamento');
});

test('hands the card to the agent named on the node', function () {
    [$conversation] = leadNodeFixture(fn ($s, Tenant $tenant) => [
        'owner_id' => User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bruno'])->id,
    ]);

    (new FlowExecutor)->startFlow($conversation);

    expect(Lead::sole()->owner?->name)->toBe('Bruno');
});

test('without a stage it only makes sure the lead exists', function () {
    [$conversation, , $stages] = leadNodeFixture(fn () => [], autoCreate: false);

    (new FlowExecutor)->startFlow($conversation);

    expect(Lead::sole()->stage_id)->toBe($stages['Novo contato']->id)
        ->and(Fx::notes($conversation, LeadNodes::INFO_STAGE_CHANGED))->toHaveCount(0)
        ->and(Fx::sentTexts($conversation))->toBe(['Obrigado!']);
});

test('a plan without the CRM leaves the funnel alone and the flow carries on', function () {
    config(['services.billing.enforce' => true]);

    [$conversation, , $stages] = leadNodeFixture(fn ($s) => ['stage_id' => $s['Proposta']->id]);

    (new FlowExecutor)->startFlow($conversation);

    expect(Lead::count())->toBe(0)
        ->and(Fx::sentTexts($conversation))->toBe(['Obrigado!']);
});

test('a lead node only saves with this workspace\'s stages and agents', function () {
    $user = Fx::user();
    $flow = Fx::flow($user->tenant);
    $start = Fx::start($flow);

    $ownStage = leadNodeStages($user->tenant)['Proposta'];
    $foreignTenant = Fx::tenant();
    $foreignStage = leadNodeStages($foreignTenant)['Proposta'];
    $foreignAgent = User::factory()->create(['tenant_id' => $foreignTenant->id]);

    $payload = fn (array $data) => [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['id' => 'lead', 'type' => 'lead', 'data' => $data, 'position_x' => 280, 'position_y' => 0],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'lead', 'condition_value' => null],
        ],
    ];

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload(['stage_id' => $foreignStage->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('nodes.1.data.stage_id');

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload(['stage_id' => $ownStage->id, 'owner_id' => $foreignAgent->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('nodes.1.data.owner_id');

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload([
        'pipeline_id' => $ownStage->pipeline_id,
        'stage_id' => $ownStage->id,
        'only_forward' => true,
    ]))->assertOk();
});
