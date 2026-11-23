<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Incident\DeliveryKind;
use App\Enums\Incident\DeliveryStatus;
use App\Enums\Incident\IncidentStatus;
use App\Enums\Incident\MatchMode;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Incident;
use App\Models\IncidentDelivery;
use App\Models\Message;
use App\Models\User;
use App\Services\Incident\IncidentMatcher;
use App\Services\Incident\IncidentResponder;
use App\Services\Incident\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\IncidentFixtures as F;

uses(RefreshDatabase::class);

/**
 * The case that started this: ProxyBR's DNS went down, and the notice was sent
 * from outside through the API — once per message, with the flow answering on
 * top. These pin what Pingly does instead.
 */
beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]])]);
});

/** Bodies of what went out to the customer, in order (notes left out). */
function incidentOutgoing(): array
{
    return Message::where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')
        ->pluck('body')
        ->all();
}

// ---------------------------------------------------------------------------
// The matcher
// ---------------------------------------------------------------------------

test('keywords match at the start of a word, as phrases, without accents or case', function () {
    $keywords = ['prox', 'conect', 'fora do ar', 'não funciona'];

    $judge = fn (string $text) => IncidentMatcher::judge(MatchMode::Keywords, $keywords, [], MessageType::Text, $text);

    expect($judge('meu proxy caiu')->keyword)->toBe('prox')
        ->and($judge('Não CONSIGO conectar!!')->keyword)->toBe('conect')
        ->and($judge('tá tudo fora do ar?')->keyword)->toBe('fora do ar')
        ->and($judge('nao funciona nada')->keyword)->toBe('não funciona')
        ->and($judge('o ar está fora')->matched())->toBeFalse()
        ->and($judge('quero o boleto de novembro')->outcome)->toBe('no_match');
});

test('a keyword is not found inside another word', function () {
    $verdict = IncidentMatcher::judge(MatchMode::Keywords, ['dns'], [], MessageType::Text, 'meu cadnsxyz');

    expect($verdict->matched())->toBeFalse();
});

test('an exclusion keyword wins over a match', function () {
    $verdict = IncidentMatcher::judge(MatchMode::Keywords, ['prox'], ['comprar'], MessageType::Text, 'quero comprar mais 10 proxies');

    expect($verdict->outcome)->toBe('no_match')
        ->and($verdict->excluded)->toBe('comprar');
});

test('greetings, button taps and media without text do not count in keyword mode', function () {
    $judge = fn (MessageType $type, ?string $text) => IncidentMatcher::judge(MatchMode::Keywords, ['prox'], [], $type, $text);

    expect($judge(MessageType::Text, 'Oi! Bom dia, tudo bem?')->reason)->toBe('greeting')
        ->and($judge(MessageType::Interactive, 'Suporte proxy')->reason)->toBe('button')
        ->and($judge(MessageType::Audio, null)->reason)->toBe('no_text')
        ->and($judge(MessageType::Image, null)->reason)->toBe('no_text')
        ->and($judge(MessageType::Image, 'erro no proxy')->keyword)->toBe('prox');
});

test('in all mode everything counts', function () {
    $judge = fn (MessageType $type, ?string $text) => IncidentMatcher::judge(MatchMode::All, [], [], $type, $text);

    expect($judge(MessageType::Text, 'oi')->matched())->toBeTrue()
        ->and($judge(MessageType::Audio, null)->matched())->toBeTrue()
        ->and($judge(MessageType::Interactive, 'Suporte')->matched())->toBeTrue();
});

test('keywords are cleaned of repeats that only differ in case or accents', function () {
    expect(IncidentMatcher::cleanKeywords([' Caiu ', 'caiu', 'CAÍU', 'dns', 42]))->toBe(['Caiu', 'dns']);
});

// ---------------------------------------------------------------------------
// Answering customers who write in
// ---------------------------------------------------------------------------

test('all mode: the first message gets the notice instead of the flow, once', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    F::incident($owner, $connection);

    F::deliver($connection, F::text('oi'));
    F::deliver($connection, F::text('alguém aí?'));

    $conversation = Conversation::sole();

    expect(incidentOutgoing())->toBe([
        'Olá Maria! Identificamos uma instabilidade de DNS.',
        // The second message is the customer's again: the flow greets them.
        F::GREETING,
    ])
        ->and(IncidentDelivery::where('kind', DeliveryKind::Notice)->where('status', DeliveryStatus::Sent)->count())->toBe(1)
        ->and($conversation->status)->toBe(ConversationStatus::Pending);
});

test('the notice is marked on the bubble, and the message it answered is not fed to the flow', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    $incident = F::incident($owner, $connection, ['match_mode' => 'keywords', 'keywords' => ['caiu']]);

    F::deliver($connection, F::text('meu proxy caiu'));

    $inbound = Message::where('sender_type', SenderType::Incoming)->sole();
    $notice = Message::where('sender_type', SenderType::Outgoing)->sole();

    expect($inbound->meta[IncidentResponder::HANDLED_META]['incident_id'] ?? null)->toBe($incident->id)
        ->and(Message::whereKey($inbound->id)->exceptIncidentHandled()->exists())->toBeFalse();

    $meta = (new MessageResource($notice))->resolve()['meta'];

    expect($meta['incident'])->toBe([
        'id' => $incident->id,
        'kind' => 'notice',
        'title' => 'DNS ProxyBR',
        'keyword' => 'caiu',
    ]);
});

test('keyword mode: a first message about something else decides for the whole incident', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    F::incident($owner, $connection, ['match_mode' => 'keywords', 'keywords' => ['prox', 'caiu']]);

    F::deliver($connection, F::text('quero o boleto de novembro'));
    F::deliver($connection, F::text('e o proxy tá off também'));

    expect(incidentOutgoing())->toBe([F::GREETING])
        ->and(IncidentDelivery::sole()->status)->toBe(DeliveryStatus::NotMatched);
});

test('keyword mode: a greeting does not decide, the next message does', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    F::incident($owner, $connection, ['match_mode' => 'keywords', 'keywords' => ['fora do ar']]);

    F::deliver($connection, F::text('oi'));
    F::deliver($connection, F::text('tá tudo fora do ar?'));

    expect(incidentOutgoing())->toBe([
        F::GREETING,
        'Olá Maria! Identificamos uma instabilidade de DNS.',
    ])
        ->and(IncidentDelivery::sole()->matched_keyword)->toBe('fora do ar');
});

test('a button tap does not decide in keyword mode', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    F::incident($owner, $connection, ['match_mode' => 'keywords', 'keywords' => ['prox']]);

    F::deliver($connection, F::buttonTap('btn_1', 'Suporte proxy'));

    expect(IncidentDelivery::count())->toBe(0);
});

test('an attendant\'s conversation is left to the attendant unless the incident says otherwise', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id]);

    F::deliver($connection, F::text('primeira mensagem antes do incidente'));
    Conversation::sole()->update(['status' => ConversationStatus::Active, 'user_id' => $agent->id]);
    Message::query()->delete();

    $incident = F::incident($owner, $connection);
    F::deliver($connection, F::text('o proxy caiu'));

    expect(IncidentDelivery::count())->toBe(0);

    app(IncidentService::class)->update($incident, ['reply_in_agent_threads' => true], $owner);
    F::deliver($connection, F::text('ainda caído'));

    expect(IncidentDelivery::where('status', DeliveryStatus::Sent)->count())->toBe(1);
});

test('continue_flow sends the notice and still runs the flow', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    F::incident($owner, $connection, ['continue_flow' => true]);

    F::deliver($connection, F::text('oi'));

    expect(incidentOutgoing())->toContain(F::GREETING)
        ->toContain('Olá Maria! Identificamos uma instabilidade de DNS.');
});

test('another connection, an expired incident, and a resolved one all leave the flow alone', function () {
    $owner = F::owner();
    $covered = F::connection($owner, '106540352242922', 'Suporte');
    $other = F::connection($owner, '222222222222222', 'Vendas');
    $incident = F::incident($owner, $covered);

    F::deliver($other, F::text('oi', '222222222222222'));
    expect(incidentOutgoing())->toBe([F::GREETING]);

    $incident->forceFill(['ends_at' => now()->subMinute()])->save();
    F::deliver($covered, F::text('oi'));

    expect(incidentOutgoing())->toBe([F::GREETING, F::GREETING])
        ->and(IncidentDelivery::count())->toBe(0);
});

test('two messages racing for one conversation produce one decision', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    $incident = F::incident($owner, $connection);

    F::deliver($connection, F::text('oi'));
    $conversation = Conversation::sole();
    $verdict = IncidentMatcher::judge(MatchMode::All, [], [], MessageType::Text, 'de novo');

    // The second worker arrives after the first already claimed.
    expect(IncidentResponder::claim($incident, $conversation, null, $verdict, \App\Enums\Incident\DeliverySource::Reactive))->toBeFalse()
        ->and(IncidentDelivery::count())->toBe(1);
});

test('a contact with no real name gets a tidy sentence, not their number', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    F::incident($owner, $connection, ['message' => 'Olá {{contact.first_name}}, estamos com instabilidade.']);

    F::deliver($connection, F::text('oi', name: F::WA_ID));

    expect(incidentOutgoing()[0])->toBe('Olá, estamos com instabilidade.');
});

// ---------------------------------------------------------------------------
// Opening, looking back, resolving, the clock
// ---------------------------------------------------------------------------

test('a second incident on the same connection at the same time is refused', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    F::incident($owner, $connection);

    $this->actingAs($owner)
        ->postJson('/api/incidents', [
            'title' => 'Outro',
            'connection_ids' => [$connection->id],
            'message' => 'x',
            'duration_minutes' => 60,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'incident_overlap');
});

test('keyword mode without keywords is refused', function () {
    $owner = F::owner();
    $connection = F::connection($owner);

    $this->actingAs($owner)
        ->postJson('/api/incidents', [
            'title' => 'DNS',
            'connection_ids' => [$connection->id],
            'match_mode' => 'keywords',
            'keywords' => [],
            'message' => 'x',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('keywords');
});

test('the people who wrote just before the incident are told too, by the same rule', function () {
    $owner = F::owner();
    $connection = F::connection($owner);

    F::deliver($connection, F::text('meu proxy caiu', waId: '5511999990001', name: 'Ana'));
    F::deliver($connection, F::text('quero o boleto', waId: '5511999990002', name: 'Bruno'));
    Message::query()->update(['created_at' => now()->subMinutes(10)]);

    F::incident($owner, $connection, [
        'match_mode' => 'keywords',
        'keywords' => ['prox'],
        'notify_recent_minutes' => 30,
    ]);

    $rows = IncidentDelivery::orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->map->value->all())->toEqualCanonicalizing(['sent', 'not_matched'])
        ->and($rows->firstWhere('status', DeliveryStatus::Sent)->source->value)->toBe('retro');
});

test('resolving tells everyone who was told, except threads already closed when asked', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    $incident = F::incident($owner, $connection, ['resolution_message' => 'Tudo normalizado ✅']);

    F::deliver($connection, F::text('oi', waId: '5511999990001', name: 'Ana'));
    F::deliver($connection, F::text('oi', waId: '5511999990002', name: 'Bruno'));

    $closed = Conversation::orderBy('id')->first();
    $closed->update(['status' => ConversationStatus::Resolved]);

    $this->actingAs($owner)
        ->postJson("/api/incidents/{$incident->id}/resolve", [
            'send_resolution' => true,
            'resolution_audience' => 'open',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved');

    expect(IncidentDelivery::where('kind', DeliveryKind::Resolution)->where('status', DeliveryStatus::Sent)->count())->toBe(1)
        ->and(Message::where('body', 'Tudo normalizado ✅')->count())->toBe(1);

    // And it stops answering.
    F::deliver($connection, F::text('oi', waId: '5511999990003', name: 'Carla'));
    expect(IncidentDelivery::where('kind', DeliveryKind::Notice)->count())->toBe(2);
});

test('the tick starts a scheduled incident, warns before the end and ends an expired one', function () {
    $owner = F::owner();
    $connection = F::connection($owner);

    $incident = F::incident($owner, $connection, [
        'starts_at' => now()->addMinutes(10)->toIso8601String(),
        'duration_minutes' => 60,
    ]);

    expect($incident->status)->toBe(IncidentStatus::Scheduled);

    $this->travel(11)->minutes();
    $this->artisan('incidents:tick')->assertSuccessful();
    expect($incident->fresh()->status)->toBe(IncidentStatus::Active);

    $this->travel(35)->minutes();
    $this->artisan('incidents:tick')->assertSuccessful();
    expect($incident->fresh()->reminder_sent_at)->not->toBeNull();

    $this->travel(30)->minutes();
    $this->artisan('incidents:tick')->assertSuccessful();
    expect($incident->fresh()->status)->toBe(IncidentStatus::Expired);
});

test('a not-matched conversation can be told by hand from the incident page', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    $incident = F::incident($owner, $connection, ['match_mode' => 'keywords', 'keywords' => ['prox']]);

    F::deliver($connection, F::text('quero o boleto'));
    $row = IncidentDelivery::sole();

    $this->actingAs($owner)
        ->postJson("/api/incidents/{$incident->id}/deliveries/{$row->id}/send")
        ->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.source', 'manual');
});

// ---------------------------------------------------------------------------
// Who may see what
// ---------------------------------------------------------------------------

test('the banner lists only incidents on the connections a person can see', function () {
    $owner = F::owner();
    $suporte = F::connection($owner, '106540352242922', 'Suporte');
    $vendas = F::connection($owner, '222222222222222', 'Vendas');
    F::incident($owner, $suporte);

    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id, 'whatsapp_verified_at' => now()]);
    $agent->connections()->sync([$vendas->id]);

    $this->actingAs($agent)->getJson('/api/incidents/active')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($owner)->getJson('/api/incidents/active')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'DNS ProxyBR');

    // Without the permission the page itself is closed.
    $this->actingAs($agent)->getJson('/api/incidents')->assertForbidden();
});

test('the keyword test box answers with the real matcher', function () {
    $owner = F::owner();

    $this->actingAs($owner)
        ->postJson('/api/incidents/keywords/test', [
            'text' => 'Oi, bom dia!',
            'match_mode' => 'keywords',
            'keywords' => ['prox'],
        ])
        ->assertOk()
        ->assertJsonPath('data.outcome', 'not_counted')
        ->assertJsonPath('data.reason', 'greeting');
});

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

test('another system can open and resolve an incident by public connection id', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    [, $key] = \App\Models\ApiKey::issue($owner->tenant, 'Monitor', $owner);

    $created = $this->withHeader('X-Api-Key', $key)
        ->postJson('/api/v1/incidents', [
            'title' => 'DNS',
            'connection_ids' => [$connection->public_id],
            'match_mode' => 'keywords',
            'keywords' => ['prox', 'caiu'],
            'message' => 'Instabilidade de DNS.',
            'duration_minutes' => 120,
        ])
        ->assertCreated()
        ->assertJsonPath('data.connection_ids.0', $connection->public_id)
        ->assertJsonPath('data.source', 'api')
        ->json('data');

    F::deliver($connection, F::text('meu proxy caiu'));
    expect(incidentOutgoing())->toBe(['Instabilidade de DNS.']);

    $this->withHeader('X-Api-Key', $key)
        ->postJson("/api/v1/incidents/{$created['id']}/resolve", [
            'send_resolution' => true,
            'resolution_message' => 'Normalizado.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved');

    expect(incidentOutgoing())->toBe(['Instabilidade de DNS.', 'Normalizado.']);
});

test('a connection id from another workspace reads like one that does not exist', function () {
    $owner = F::owner();
    $stranger = F::owner();
    $theirs = F::connection($stranger);
    [, $key] = \App\Models\ApiKey::issue($owner->tenant, 'Monitor', $owner);

    $this->withHeader('X-Api-Key', $key)
        ->postJson('/api/v1/incidents', [
            'title' => 'DNS',
            'connection_ids' => [$theirs->public_id],
            'message' => 'x',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('connection_ids');

    expect(Incident::count())->toBe(0);
});
