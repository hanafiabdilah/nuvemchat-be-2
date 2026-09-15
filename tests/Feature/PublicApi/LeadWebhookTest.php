<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Lead\LeadStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Models\LeadStage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookDispatcher;
use App\Services\Webhooks\WebhookEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\LeadAttendanceFixtures as Attendance;
use Tests\Support\LeadIntakeFixtures as F;

uses(RefreshDatabase::class);

const LWH_URL = 'https://hub.example.com/pingly';

beforeEach(function () {
    config(['services.billing.enforce' => false]);

    // One fake for the whole test, answering whatever the test last set: a
    // second Http::fake() would only append a stub the first one shadows.
    $this->hubStatus = 200;
    $this->hubBody = ['ok' => true];
    Http::fake(['hub.example.com/*' => fn () => Http::response($this->hubBody, $this->hubStatus)]);
});

function lwhEndpoint(User $owner, array $events = WebhookEvents::SUBSCRIBABLE): WebhookEndpoint
{
    return WebhookEndpoint::create([
        'tenant_id' => $owner->tenant_id,
        'url' => LWH_URL,
        'events' => $events,
        'secret' => 'whsec_test_secret',
        'is_active' => true,
    ]);
}

/** Sends a lead through the public API; returns [key, lead, conversation]. */
function lwhApiLead($test, User $owner, string $reference = 'signup:1', string $phone = '5511987654321'): array
{
    $key = F::key($owner);

    $test->withHeaders(['X-Api-Key' => $key])
        ->postJson('/api/v1/leads', ['name' => 'Maria', 'phone' => $phone, 'reference' => $reference])
        ->assertCreated();

    $intake = LeadIntake::where('reference', $reference)->sole();

    return [$key, Lead::find($intake->lead_id), Conversation::find($intake->conversation_id)];
}

/** @return list<array<string, mixed>> the bodies sent for one event, oldest first */
function lwhSent(string $event): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => ($pair[0]->header('X-Pingly-Event')[0] ?? null) === $event)
        ->map(fn ($pair) => json_decode($pair[0]->body(), true))
        ->values()
        ->all();
}

/*
|--------------------------------------------------------------------------
| POST /v1/leads/close
|--------------------------------------------------------------------------
*/

it('closes a lead by reference as won with its value, and is safe to retry', function () {
    $owner = F::owner();
    F::connection($owner);
    [$key, $lead] = lwhApiLead($this, $owner);

    $this->withHeaders(['X-Api-Key' => $key])
        ->postJson('/api/v1/leads/close', ['reference' => 'signup:1', 'status' => 'won', 'value' => 197.9])
        ->assertOk()
        ->assertJsonPath('data.id', $lead->id)
        ->assertJsonPath('data.status', 'won')
        ->assertJsonPath('data.value', 197.9)
        ->assertJsonPath('data.stage.name', 'Cliente')
        ->assertJsonPath('data.changed', true);

    $this->withHeaders(['X-Api-Key' => $key])
        ->postJson('/api/v1/leads/close', ['reference' => 'signup:1', 'status' => 'won', 'value' => 197.9])
        ->assertOk()
        ->assertJsonPath('data.changed', false);

    $this->withHeaders(['X-Api-Key' => $key])
        ->postJson('/api/v1/leads/close', ['reference' => 'signup:1', 'status' => 'lost'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'lead_already_closed');

    $this->withHeaders(['X-Api-Key' => $key])
        ->postJson('/api/v1/leads/close', ['reference' => 'nope', 'status' => 'won'])
        ->assertNotFound()
        ->assertJsonPath('code', 'lead_not_found');

    expect($lead->fresh()->status)->toBe(LeadStatus::Won)
        ->and((float) $lead->fresh()->value)->toBe(197.9);
});

it('closes a lead as lost with the reason, and validates the request', function () {
    $owner = F::owner();
    F::connection($owner);
    [$key, $lead] = lwhApiLead($this, $owner);

    $this->withHeaders(['X-Api-Key' => $key])
        ->postJson('/api/v1/leads/close', ['reference' => 'signup:1', 'status' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->withHeaders(['X-Api-Key' => $key])
        ->postJson('/api/v1/leads/close', ['reference' => 'signup:1', 'status' => 'lost', 'lost_reason' => 'Achou mais barato'])
        ->assertOk()
        ->assertJsonPath('data.status', 'lost')
        ->assertJsonPath('data.lost_reason', 'Achou mais barato')
        ->assertJsonPath('data.stage.name', 'Perdido');

    expect($lead->stageEvents()->latest('id')->first()->user_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Events
|--------------------------------------------------------------------------
*/

it('signs lead.won and carries the evidence that an agent worked the conversation', function () {
    $owner = F::owner();
    F::connection($owner);
    lwhEndpoint($owner);
    [$key, , $conversation] = lwhApiLead($this, $owner);

    $agent = F::agent($owner, $conversation->connection);
    Attendance::reply($conversation, $agent);
    Attendance::reply($conversation, $agent);

    $this->withHeaders(['X-Api-Key' => $key])
        ->postJson('/api/v1/leads/close', ['reference' => 'signup:1', 'status' => 'won', 'value' => 197.9])
        ->assertOk();

    $won = lwhSent('lead.won');

    expect($won)->toHaveCount(1)
        ->and($won[0]['event'])->toBe('lead.won')
        ->and($won[0]['id'])->toStartWith('evt_')
        ->and($won[0]['data']['lead']['reference'])->toBe('signup:1')
        ->and($won[0]['data']['lead']['value'])->toBe(197.9)
        ->and($won[0]['data']['lead']['stage']['kind'])->toBe('won')
        ->and($won[0]['data']['previous_stage']['name'])->toBe('Atendidos')
        ->and($won[0]['data']['changed_by'])->toBeNull()
        ->and($won[0]['data']['conversation']['id'])->toBe($conversation->id)
        ->and($won[0]['data']['conversation']['connection_id'])->toBe($conversation->connection->public_id)
        ->and($won[0]['data']['conversation']['agent_messages_count'])->toBe(2)
        ->and($won[0]['data']['conversation']['first_agent_message_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    // The signature verifies against the raw body with the endpoint's secret.
    Http::assertSent(function (HttpRequest $request) {
        if (($request->header('X-Pingly-Event')[0] ?? null) !== 'lead.won') {
            return false;
        }

        preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $request->header('X-Pingly-Signature')[0], $m);

        return $m && hash_equals(hash_hmac('sha256', $m[1].'.'.$request->body(), 'whsec_test_secret'), $m[2])
            && $request->header('X-Pingly-Delivery')[0] === json_decode($request->body(), true)['id'];
    });

    expect(WebhookDelivery::where('event', 'lead.won')->sole()->status)->toBe(WebhookDelivery::DELIVERED);
});

it('announces stage moves and both kinds of assignment for an API lead', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    lwhEndpoint($owner);
    [, $lead, $conversation] = lwhApiLead($this, $owner);
    $agent = F::agent($owner, $connection);

    // An agent takes the conversation.
    $conversation->update(['user_id' => $agent->id, 'status' => ConversationStatus::Active]);

    // Someone makes them responsible for the lead.
    $lead->update(['owner_id' => $agent->id]);

    // And moves the card on the board.
    $qualificacao = LeadStage::where('pipeline_id', $lead->pipeline_id)->where('name', 'Qualificação')->sole();
    $lead->fresh()->moveToStage($qualificacao, $owner);

    $assigned = lwhSent('lead.assigned');
    $moved = lwhSent('lead.stage_changed');

    expect(collect($assigned)->pluck('data.assignment.type')->all())->toBe(['conversation', 'owner'])
        ->and($assigned[0]['data']['assignment']['assigned_to']['email'])->toBe($agent->email)
        ->and($assigned[0]['data']['conversation']['assigned_to']['id'])->toBe($agent->id)
        ->and($assigned[1]['data']['lead']['owner']['id'])->toBe($agent->id)
        ->and($moved)->toHaveCount(1)
        ->and($moved[0]['data']['lead']['stage']['name'])->toBe('Qualificação')
        ->and($moved[0]['data']['changed_by']['id'])->toBe($owner->id)
        ->and($moved[0]['data']['conversation'])->toHaveKeys(['agent_messages_count', 'first_agent_message_at']);
});

it('sends nothing for leads that did not come through the API, or for events an endpoint did not choose', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    lwhEndpoint($owner, [WebhookEvents::LEAD_WON]);

    // A contact who wrote in on their own.
    $contact = Contact::create(['tenant_id' => $owner->tenant_id, 'external_id' => '5521900000000', 'name' => 'Walk-in', 'channel' => $connection->channel]);
    Conversation::create(['contact_id' => $contact->id, 'connection_id' => $connection->id, 'external_id' => '5521900000000', 'status' => ConversationStatus::Pending]);
    $walkIn = Lead::where('contact_id', $contact->id)->sole();
    $walkIn->moveToStage(LeadStage::where('pipeline_id', $walkIn->pipeline_id)->where('name', 'Cliente')->sole(), $owner);

    [$key, $lead] = lwhApiLead($this, $owner);
    $lead->moveToStage(LeadStage::where('pipeline_id', $lead->pipeline_id)->where('name', 'Proposta')->sole(), $owner);

    expect(WebhookDelivery::count())->toBe(0);

    $this->withHeaders(['X-Api-Key' => $key])
        ->postJson('/api/v1/leads/close', ['reference' => 'signup:1', 'status' => 'won'])
        ->assertOk();

    expect(WebhookDelivery::pluck('event')->all())->toBe(['lead.won']);
});

it('records a refused delivery without failing the change, and retries it', function () {
    $this->hubStatus = 503;
    $this->hubBody = 'down for maintenance';

    $owner = F::owner();
    F::connection($owner);
    lwhEndpoint($owner, [WebhookEvents::LEAD_WON]);
    [$key] = lwhApiLead($this, $owner);

    $this->withHeaders(['X-Api-Key' => $key])
        ->postJson('/api/v1/leads/close', ['reference' => 'signup:1', 'status' => 'won'])
        ->assertOk();

    $delivery = WebhookDelivery::sole();

    expect($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(503)
        ->and($delivery->response_body)->toBe('down for maintenance');

    // On a real queue the job throws so the worker retries it later.
    $delivery->forceFill(['status' => WebhookDelivery::PENDING])->save();

    expect(fn () => (new DeliverWebhook($delivery->id))->handle(app(WebhookDispatcher::class)))
        ->toThrow(RuntimeException::class);

    expect($delivery->fresh()->attempts)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Managing endpoints
|--------------------------------------------------------------------------
*/

it('manages endpoints: the secret is shown once, https only, no internal addresses', function () {
    $owner = F::owner();

    $created = $this->actingAs($owner)
        ->postJson('/api/webhooks', ['url' => LWH_URL, 'events' => ['lead.won', 'lead.lost']])
        ->assertCreated();

    $secret = $created->json('secret');

    expect($secret)->toStartWith(WebhookEndpoint::SECRET_PREFIX)
        ->and(WebhookEndpoint::sole()->secret)->toBe($secret);

    $list = $this->actingAs($owner)->getJson('/api/webhooks')->assertOk();

    expect($list->getContent())->not->toContain($secret)
        ->and($list->json('events'))->toBe(WebhookEvents::SUBSCRIBABLE)
        ->and($list->json('data.0.secret_hint'))->toEndWith(substr($secret, -4));

    foreach (['http://hub.example.com/x', 'https://localhost/x', 'https://10.0.0.5/x', 'https://127.0.0.1/x', 'https://queue/x', 'not a url'] as $url) {
        $this->actingAs($owner)
            ->postJson('/api/webhooks', ['url' => $url, 'events' => ['lead.won']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    $this->actingAs($owner)
        ->postJson('/api/webhooks', ['url' => LWH_URL, 'events' => ['message.sent']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('events.0');

    $id = WebhookEndpoint::sole()->id;

    $rotated = $this->actingAs($owner)->postJson("/api/webhooks/{$id}/rotate-secret")->assertOk();
    expect($rotated->json('secret'))->not->toBe($secret);

    $this->actingAs($owner)
        ->postJson("/api/webhooks/{$id}/test")
        ->assertOk()
        ->assertJsonPath('data.event', 'ping')
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.response_status', 200);

    $this->actingAs($owner)->getJson("/api/webhooks/{$id}/deliveries")->assertOk()->assertJsonPath('data.0.event', 'ping');

    $this->actingAs($owner)->putJson("/api/webhooks/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
});

it('keeps webhooks to people allowed to manage them, and caps how many a workspace has', function () {
    $owner = F::owner();
    $agent = F::agent($owner);

    $this->actingAs($agent)->getJson('/api/webhooks')->assertForbidden();

    for ($i = 0; $i < WebhookEndpoint::MAX_PER_TENANT; $i++) {
        lwhEndpoint($owner);
    }

    $this->actingAs($owner)
        ->postJson('/api/webhooks', ['url' => LWH_URL, 'events' => ['lead.won']])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'webhook_limit');
});
