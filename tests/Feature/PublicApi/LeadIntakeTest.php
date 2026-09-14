<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Lead\LeadSource;
use App\Enums\Lead\StageKind;
use App\Enums\Message\MessageType;
use App\Exceptions\ChannelCapabilityException;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Models\LeadStage;
use App\Models\Message;
use App\Models\Tag;
use App\Models\TenantApiKey;
use App\Services\Billing\SubscriptionGate;
use App\Services\Lead\LeadIntakeService;
use App\Services\Lead\LeadResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadIntakeFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.billing.enforce' => false]);
});

/*
|--------------------------------------------------------------------------
| Workspace keys
|--------------------------------------------------------------------------
*/

it('creates a workspace key that is shown once and authenticates', function () {
    $owner = F::owner();
    F::connection($owner);

    $created = $this->actingAs($owner)->postJson('/api/api-keys', ['name' => 'ProxyBR'])->assertCreated();
    $plain = $created->json('plain_key');

    expect($plain)->toStartWith(TenantApiKey::PREFIX)
        ->and(TenantApiKey::sole()->key_hash)->toBe(hash('sha256', $plain));

    $list = $this->actingAs($owner)->getJson('/api/api-keys')->assertOk();

    expect($list->json('data.0.name'))->toBe('ProxyBR')
        ->and($list->getContent())->not->toContain($plain)
        ->and($list->getContent())->not->toContain('key_hash');

    $this->withHeaders(['X-Api-Key' => $plain])
        ->getJson('/api/v1/connections')
        ->assertOk()
        ->assertJsonPath('data.0.accepts_leads', true);

    expect(TenantApiKey::sole()->last_used_at)->not->toBeNull();
});

it('lets only api-keys.manage handle workspace keys', function () {
    $owner = F::owner();
    $agent = F::agent($owner);

    $this->actingAs($agent)->getJson('/api/api-keys')->assertForbidden();
    $this->actingAs($agent)->postJson('/api/api-keys', ['name' => 'Site'])->assertForbidden();
});

it('stops accepting a key the moment it is revoked', function () {
    $owner = F::owner();
    $plain = F::key($owner);

    $this->actingAs($owner)->deleteJson('/api/api-keys/'.TenantApiKey::sole()->id)->assertOk();

    $this->withHeaders(['X-Api-Key' => $plain])
        ->getJson('/api/v1/connections')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'api_key_invalid');
});

it('tells the caller what is wrong with the key it sent', function () {
    $owner = F::owner();
    $connectionKey = str_repeat('a', 64);
    F::connection($owner)->forceFill(['api_key' => $connectionKey])->save();

    $this->getJson('/api/v1/connections')->assertUnauthorized()->assertJsonPath('code', 'api_key_missing');

    $this->withHeaders(['X-Api-Key' => 'pk_ws_not-a-real-key'])
        ->getJson('/api/v1/connections')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'api_key_invalid');

    // The likeliest mix-up on a page that lists both kinds of key.
    $this->withHeaders(['X-Api-Key' => $connectionKey])
        ->postJson('/api/v1/leads', [])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'connection_key_not_accepted');

    $this->flushHeaders();

    $this->withToken(F::key($owner))->getJson('/api/v1/connections')->assertOk();
});

it('refuses a workspace whose subscription is suspended', function () {
    config(['services.billing.enforce' => true]);

    $gate = Mockery::mock(SubscriptionGate::class);
    $gate->shouldReceive('usable')->andReturnFalse();
    app()->instance(SubscriptionGate::class, $gate);

    $owner = F::owner();

    $this->withHeaders(['X-Api-Key' => F::key($owner)])
        ->postJson('/api/v1/leads', ['name' => 'Maria', 'phone' => '5511987654321'])
        ->assertForbidden()
        ->assertJsonPath('code', 'subscription_suspended');
});

/*
|--------------------------------------------------------------------------
| POST /v1/leads
|--------------------------------------------------------------------------
*/

it('turns a sign-up into a card, a queued thread with the form details and a first message', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    $plain = F::key($owner);

    F::fakeSends()->shouldReceive('sendMessage')->once()->andReturnUsing(F::storesMessage());

    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/leads', [
        'name' => 'Maria Souza',
        'phone' => '+55 (11) 98765-4321',
        'email' => 'maria@example.com',
        'reference' => 'proxybr:signup:42',
        'metadata' => ['plano' => 'Pro', 'pago' => false],
        'message' => 'Oi Maria! Vi que você começou seu cadastro.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.duplicate', false)
        ->assertJsonPath('data.contact.phone', '5511987654321')
        ->assertJsonPath('data.contact.created', true)
        ->assertJsonPath('data.lead.created', true)
        ->assertJsonPath('data.conversation.created', true)
        ->assertJsonPath('data.conversation.status', ConversationStatus::Pending->value)
        ->assertJsonPath('data.conversation.assigned_to', null)
        ->assertJsonPath('data.opening_message.status', 'sent');

    $lead = Lead::sole();
    $conversation = Conversation::sole();

    expect($lead->source)->toBe(LeadSource::Api)
        ->and($lead->source_connection_id)->toBe($connection->id)
        ->and($conversation->lead_id)->toBe($lead->id)
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->user_id)->toBeNull();

    $note = Message::where('message_type', MessageType::Info)->sole();

    expect($note->meta['info']['code'])->toBe(LeadIntakeService::NOTE_RECEIVED_WITH_DETAILS)
        ->and($note->meta['info']['params']['key'])->toBe('ProxyBR')
        ->and($note->meta['info']['params']['details'])->toBe('maria@example.com · plano: Pro · pago: false');
});

it('hands the thread straight to the agent named in assign_to', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    $agent = F::agent($owner, $connection, 'Ana@Example.com');

    $this->withHeaders(['X-Api-Key' => F::key($owner)])->postJson('/api/v1/leads', [
        'name' => 'João',
        'phone' => '5521998887766',
        'assign_to' => 'ana@example.com',
    ])
        ->assertCreated()
        ->assertJsonPath('data.conversation.status', ConversationStatus::Active->value)
        ->assertJsonPath('data.conversation.assigned_to.id', $agent->id)
        ->assertJsonPath('data.opening_message.status', 'not_requested');

    expect(Lead::sole()->owner_id)->toBe($agent->id);
});

it('refuses an agent who cannot open the connection, before writing anything', function () {
    $owner = F::owner();
    F::connection($owner);
    F::agent($owner);

    $this->withHeaders(['X-Api-Key' => F::key($owner)])->postJson('/api/v1/leads', [
        'name' => 'João',
        'phone' => '5521998887766',
        'assign_to' => 'ana@example.com',
    ])->assertUnprocessable()->assertJsonValidationErrors('assign_to');

    expect(Contact::count())->toBe(0)
        ->and(Lead::count())->toBe(0)
        ->and(Conversation::count())->toBe(0)
        ->and(LeadIntake::count())->toBe(0);
});

it('answers a retried reference with the first result and never sends twice', function () {
    $owner = F::owner();
    F::connection($owner);
    $plain = F::key($owner);

    F::fakeSends()->shouldReceive('sendMessage')->once()->andReturnUsing(F::storesMessage());

    $payload = ['name' => 'Maria', 'phone' => '5511987654321', 'reference' => 'signup-7', 'message' => 'Olá!'];

    $first = $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/leads', $payload)->assertCreated();

    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/leads', $payload)
        ->assertOk()
        ->assertJsonPath('data.duplicate', true)
        ->assertJsonPath('data.conversation.id', $first->json('data.conversation.id'));

    expect(LeadIntake::count())->toBe(1)
        ->and(Message::where('message_type', '!=', MessageType::Info)->count())->toBe(1);
});

it('requires a template to open a WhatsApp Official conversation, and sends it', function () {
    $owner = F::owner();
    F::connection($owner, Channel::WhatsappOfficial, 'Oficial');
    $plain = F::key($owner);

    F::fakeSends()->shouldReceive('sendTemplate')->once()
        ->withArgs(fn ($conversation, array $data) => $data['template_name'] === 'cadastro_incompleto' && $data['language'] === 'pt_BR')
        ->andReturnUsing(F::storesMessage());

    $this->withHeaders(['X-Api-Key' => $plain])
        ->postJson('/api/v1/leads', ['name' => 'Maria', 'phone' => '5511987654321', 'message' => 'Oi'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'template_required');

    expect(Contact::count())->toBe(0);

    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/leads', [
        'name' => 'Maria',
        'phone' => '5511987654321',
        'template' => ['name' => 'cadastro_incompleto', 'language' => 'pt_BR'],
    ])->assertCreated()->assertJsonPath('data.opening_message.status', 'sent');
});

it('asks which number to use when the workspace has several, and refuses an inactive one', function () {
    $owner = F::owner();
    $sales = F::connection($owner, Channel::WhatsappApiway, 'Vendas');
    F::connection($owner, Channel::WhatsappOfficial, 'Suporte');
    F::connection($owner, Channel::Telegram, 'Bot');
    $paused = F::connection($owner, Channel::WhatsappApiway, 'Antigo', ConnectionStatus::Inactive);
    $plain = F::key($owner);

    $lead = ['name' => 'Maria', 'phone' => '5511987654321'];

    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/leads', $lead)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'connection_required')
        ->assertJsonCount(2, 'connections');

    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/leads', $lead + ['connection_id' => $paused->id])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'connection_inactive');

    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/leads', $lead + ['connection_id' => $sales->id])
        ->assertCreated()
        ->assertJsonPath('data.conversation.connection_id', $sales->id);
});

it('finds the contact the inbox already has under the old spelling of a Brazilian mobile', function () {
    $owner = F::owner();
    F::connection($owner);

    $existing = Contact::create([
        'tenant_id' => $owner->tenant_id,
        'external_id' => '551187654321',
        'name' => 'Maria (WhatsApp)',
        'channel' => Channel::WhatsappApiway,
    ]);

    $this->withHeaders(['X-Api-Key' => F::key($owner)])
        ->postJson('/api/v1/leads', ['name' => 'Maria Souza', 'phone' => '5511987654321'])
        ->assertCreated()
        ->assertJsonPath('data.contact.id', $existing->id)
        ->assertJsonPath('data.contact.created', false);

    expect(Contact::count())->toBe(1)
        ->and(Message::where('message_type', MessageType::Info)->sole()->meta['info']['params']['details'])->toBe('Maria Souza')
        ->and(LeadIntakeService::phoneVariants('5511987654321'))->toBe(['5511987654321', '551187654321'])
        ->and(LeadIntakeService::phoneVariants('551187654321'))->toBe(['551187654321', '5511987654321'])
        ->and(LeadIntakeService::phoneVariants('14155550123'))->toBe(['14155550123']);
});

it('adds to an open lead someone is working instead of opening a second card', function () {
    $owner = F::owner();
    F::connection($owner);

    $contact = Contact::create([
        'tenant_id' => $owner->tenant_id,
        'external_id' => '5511987654321',
        'name' => 'Maria',
        'channel' => Channel::WhatsappApiway,
    ]);

    $lead = app(LeadResolver::class)->open($contact, null, LeadSource::Manual, $owner->tenant_id);
    [$first, $second] = LeadStage::where('pipeline_id', $lead->pipeline_id)
        ->where('kind', StageKind::Open->value)
        ->orderBy('position')
        ->take(2)
        ->get();
    $lead->moveToStage($second);

    $this->withHeaders(['X-Api-Key' => F::key($owner)])->postJson('/api/v1/leads', [
        'name' => 'Maria',
        'phone' => '5511987654321',
        'stage_id' => $first->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.lead.id', $lead->id)
        ->assertJsonPath('data.lead.created', false);

    $lead->refresh();

    expect(Lead::count())->toBe(1)
        ->and($lead->stage_id)->toBe($second->id)
        ->and($lead->source)->toBe(LeadSource::Manual);
});

it('still delivers the lead to the inbox when the first message fails', function () {
    $owner = F::owner();
    F::connection($owner);

    F::fakeSends()->shouldReceive('sendMessage')->once()
        ->andThrow(new ChannelCapabilityException('Este número não tem WhatsApp.'));

    $this->withHeaders(['X-Api-Key' => F::key($owner)])
        ->postJson('/api/v1/leads', ['name' => 'Maria', 'phone' => '5511987654321', 'message' => 'Oi!'])
        ->assertCreated()
        ->assertJsonPath('data.opening_message.status', 'failed')
        ->assertJsonPath('data.opening_message.error', 'Este número não tem WhatsApp.');

    expect(Conversation::count())->toBe(1)
        ->and(Message::where('message_type', MessageType::Info)->get()->pluck('meta.info.code')->all())
        ->toContain(LeadIntakeService::NOTE_OPENING_FAILED);
});

it('applies known tags and reports unknown ones without failing', function () {
    $owner = F::owner();
    F::connection($owner);
    Tag::create(['tenant_id' => $owner->tenant_id, 'name' => 'Cadastro sem pagamento', 'color' => '#f97316']);

    $this->withHeaders(['X-Api-Key' => F::key($owner)])->postJson('/api/v1/leads', [
        'name' => 'Maria',
        'phone' => '5511987654321',
        'tags' => ['cadastro sem pagamento', 'inexistente'],
    ])->assertCreated()->assertJsonPath('ignored_tags', null)->assertJsonPath('data.ignored_tags', ['inexistente']);

    expect(Conversation::sole()->tags->pluck('name')->all())->toBe(['Cadastro sem pagamento']);
});

it('still reaches the inbox when the plan has no CRM', function () {
    config(['services.billing.enforce' => true]);

    $gate = Mockery::mock(SubscriptionGate::class);
    $gate->shouldReceive('usable')->andReturnTrue();
    $gate->shouldReceive('feature')->andReturnFalse();
    app()->instance(SubscriptionGate::class, $gate);

    $owner = F::owner();
    F::connection($owner);

    $this->withHeaders(['X-Api-Key' => F::key($owner)])
        ->postJson('/api/v1/leads', ['name' => 'Maria', 'phone' => '5511987654321'])
        ->assertCreated()
        ->assertJsonPath('data.lead', null);

    expect(Lead::count())->toBe(0)->and(Conversation::count())->toBe(1);
});

it('rejects a phone without enough digits and nested metadata', function () {
    $owner = F::owner();
    F::connection($owner);
    $plain = F::key($owner);

    $this->withHeaders(['X-Api-Key' => $plain])
        ->postJson('/api/v1/leads', ['name' => 'Maria', 'phone' => '98765'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    $this->withHeaders(['X-Api-Key' => $plain])
        ->postJson('/api/v1/leads', ['name' => 'Maria', 'phone' => '5511987654321', 'metadata' => ['plano' => ['id' => 1]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('metadata');
});
