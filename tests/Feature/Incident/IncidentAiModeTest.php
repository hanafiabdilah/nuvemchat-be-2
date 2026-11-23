<?php

use App\Enums\Incident\DeliveryStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Http\Resources\MessageResource;
use App\Jobs\RunAiAgentTurn;
use App\Models\AiHubAgent;
use App\Models\AiHubRun;
use App\Models\AiHubTenant;
use App\Models\IncidentDelivery;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\Flow\FlowExecutor;
use App\Services\Incident\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AiAgentFixtures;
use Tests\Support\IncidentFixtures as F;

uses(RefreshDatabase::class);

/**
 * Mode IA: the notice written per customer by one of the workspace's AI
 * agents, with the fixed message as the floor it never falls through.
 */
const AI_BRIEF = 'Falha de DNS desde as 9h40. Os proxies voltam em até 1 hora; não é preciso alterar nada.';

function incidentAgent(User $owner, string $status = 'ACTIVE'): AiHubAgent
{
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

    $hubTenant = AiHubTenant::create([
        'tenant_id' => $owner->tenant_id,
        'hub_tenant_id' => 'hub-tenant-'.$owner->tenant_id,
        'external_id' => 'Pingly_'.$owner->tenant_id,
        'name' => 'Pingly_'.$owner->tenant_id,
        'status' => 'ACTIVE',
    ]);

    return AiHubAgent::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_agent_id' => 'hub-agent-'.$owner->tenant_id,
        'external_id' => 'agente_'.$owner->tenant_id,
        'name' => 'Atendimento',
        'model' => 'gpt-4o-mini',
        'status' => $status,
    ]);
}

function fakeHub(string $reply, int $status = 200): void
{
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]]),
        'api-ia.ipbr.pro/*' => $status === 200
            ? Http::response(['id' => 'run_1', 'status' => 'COMPLETED', 'output' => ['message' => $reply, 'handoff' => false]])
            : Http::response(['message' => 'boom'], $status),
    ]);
}

test('the notice is written by the AI from the brief and what the customer said', function () {
    fakeHub('Oi Maria! Nossos proxies estão com uma falha de DNS e voltam em até 1 hora. Sobre os 10 proxies novos, já anotamos.');

    $owner = F::owner();
    $connection = F::connection($owner);
    $agent = incidentAgent($owner);

    F::incident($owner, $connection, [
        'reply_mode' => 'ai',
        'ai_hub_agent_id' => $agent->id,
        'ai_brief' => AI_BRIEF,
    ]);

    F::deliver($connection, F::text('meu proxy caiu, e quero comprar mais 10'));

    $notice = Message::where('sender_type', SenderType::Outgoing)->where('message_type', '!=', MessageType::Info)->sole();

    expect($notice->body)->toStartWith('Oi Maria! Nossos proxies')
        ->and($notice->sent_by_ai_hub_agent_id)->toBe($agent->id)
        // Its own hub conversation: never written into the thread's history,
        // and kept off the key that tells the transcript "the hub knows this".
        ->and($notice->meta)->not->toHaveKey('ai_hub_run_id')
        ->and($notice->meta['incident_ai_run_id'] ?? null)->toBe(AiHubRun::sole()->id)
        ->and((new MessageResource($notice))->resolve()['meta']['incident']['ai'] ?? null)->toBeTrue();

    $run = AiAgentFixtures::hubRuns()[0];

    expect($run['conversation']['externalId'])->toStartWith('incident:')
        ->and($run['message']['content'])
        ->toContain(AI_BRIEF)
        ->toContain('meu proxy caiu, e quero comprar mais 10')
        ->and(IncidentDelivery::sole()->status)->toBe(DeliveryStatus::Sent)
        ->and(IncidentDelivery::sole()->reason)->toBeNull();
});

test('when the AI cannot answer, the fixed message goes and the row says why', function () {
    fakeHub('', 500);

    $owner = F::owner();
    $connection = F::connection($owner);
    $agent = incidentAgent($owner);

    F::incident($owner, $connection, [
        'reply_mode' => 'ai',
        'ai_hub_agent_id' => $agent->id,
        'ai_brief' => AI_BRIEF,
        'message' => 'Identificamos uma instabilidade de DNS.',
    ]);

    F::deliver($connection, F::text('oi'));

    $row = IncidentDelivery::sole();

    expect($row->status)->toBe(DeliveryStatus::Sent)
        ->and($row->reason)->toBe('ai_failed')
        ->and(Message::where('body', 'Identificamos uma instabilidade de DNS.')->count())->toBe(1);
});

test('an agent that was switched off falls back to the fixed message without calling the hub', function () {
    fakeHub('não deveria ser usado');

    $owner = F::owner();
    $connection = F::connection($owner);
    $agent = incidentAgent($owner);

    $incident = F::incident($owner, $connection, [
        'reply_mode' => 'ai',
        'ai_hub_agent_id' => $agent->id,
        'ai_brief' => AI_BRIEF,
        'message' => 'Identificamos uma instabilidade de DNS.',
    ]);

    $agent->update(['status' => 'PAUSED']);

    F::deliver($connection, F::text('oi'));

    expect(AiAgentFixtures::hubRuns())->toBeEmpty()
        ->and(IncidentDelivery::sole()->reason)->toBe('ai_unavailable')
        ->and(Message::where('body', 'Identificamos uma instabilidade de DNS.')->count())->toBe(1);
});

test('Mode IA needs an agent of this workspace and a brief', function () {
    $owner = F::owner();
    $connection = F::connection($owner);

    $stranger = User::factory()->create();
    $strangerTenant = Tenant::create(['user_id' => $stranger->id]);
    $stranger->forceFill(['tenant_id' => $strangerTenant->id])->save();
    $theirs = incidentAgent($stranger);

    $base = [
        'title' => 'DNS',
        'connection_ids' => [$connection->id],
        'message' => 'x',
        'reply_mode' => 'ai',
        'ai_brief' => AI_BRIEF,
    ];

    $this->actingAs($owner)->postJson('/api/incidents', $base + ['ai_hub_agent_id' => $theirs->id])
        ->assertStatus(422)->assertJsonValidationErrors('ai_hub_agent_id');

    $mine = incidentAgent($owner);

    $this->actingAs($owner)->postJson('/api/incidents', array_merge($base, ['ai_hub_agent_id' => $mine->id, 'ai_brief' => '']))
        ->assertStatus(422)->assertJsonValidationErrors('ai_brief');

    $this->actingAs($owner)->postJson('/api/incidents', $base + ['ai_hub_agent_id' => $mine->id])
        ->assertCreated()
        ->assertJsonPath('data.reply_mode', 'ai')
        ->assertJsonPath('data.ai_agent.name', 'Atendimento');
});

test('while an incident with a brief is live, the AI node hears about it before the customer', function () {
    AiAgentFixtures::fakeChannelsAndHub('Sim, já sabemos — volta em até 1 hora.');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = AiAgentFixtures::flow();
    $conversation->messages()->create([
        'external_id' => 'wamid.'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'ainda não voltou',
        'sent_at' => now(),
    ]);

    $tenant = $conversation->connection->tenant;
    $data = app(IncidentService::class)->validate([
        'title' => 'DNS',
        'connection_ids' => [$conversation->connection_id],
        'message' => 'Instabilidade de DNS.',
        'ai_brief' => AI_BRIEF,
    ]);
    app(IncidentService::class)->create($tenant, $data, null);

    (new FlowExecutor)->startFlow($conversation->fresh());
    Queue::pushed(RunAiAgentTurn::class)->first()->handle();

    $content = AiAgentFixtures::hubRuns()[0]['message']['content'];

    expect($content)->toStartWith('[Service notice from the business, in effect right now')
        ->toContain(AI_BRIEF)
        ->toContain('ainda não voltou');
});

test('with no incident live, the AI node input is exactly what it was', function () {
    AiAgentFixtures::fakeChannelsAndHub('Oi!');
    Queue::fake([RunAiAgentTurn::class]);

    [$conversation] = AiAgentFixtures::flow();
    $conversation->messages()->create([
        'external_id' => 'wamid.'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'meu pedido não chegou',
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->startFlow($conversation->fresh());
    Queue::pushed(RunAiAgentTurn::class)->first()->handle();

    expect(AiAgentFixtures::hubRuns()[0]['message']['content'])->not->toContain('Service notice');
});
