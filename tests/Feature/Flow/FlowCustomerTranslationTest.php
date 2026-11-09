<?php

use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\WarmFlowTranslations;
use App\Models\AiHubProviderCredential;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowAssistantSetting;
use App\Models\FlowTextTranslation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\AiAgentHub\AiAgentHubService;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\FlowLanguages;
use App\Services\Flow\FlowLocalization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function () {
    FlowLocalization::flush();
    config(['flow.translation.warm' => false]);
    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'hub-tenant-token');
});

/** The workspace's own key for the assistant, which the translation runs on. */
function localizationKey(Tenant $tenant): void
{
    $hubTenant = app(AiAgentHubService::class)->createTenant($tenant);
    $credential = AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $hubTenant->id, 'hub_provider_credential_id' => 'hub-cred-'.uniqid(),
        'provider' => 'OPENAI', 'name' => 'Minha chave', 'status' => 'ACTIVE',
    ]);
    FlowAssistantSetting::create([
        'tenant_id' => $tenant->id, 'ai_hub_provider_credential_id' => $credential->id, 'model' => 'gpt-4o',
        'hub_agent_id' => 'hub-agent-1', 'agent_external_id' => config('app.name').'_'.$hubTenant->id.'_flow_assistant',
    ]);
}

/**
 * start → greeting → menu (two buttons) → one message per button.
 *
 * @return array{0: Tenant, 1: Flow}
 */
function localizationFlow(bool $enabled = true, bool $withKey = true): array
{
    $tenant = Fx::tenant();
    $flow = Fx::flow($tenant, 'Vendas');
    $flow->update(['translate_to_customer' => $enabled]);

    $hello = Fx::say($flow, 'Olá {{contact.name}}!', 280);
    $menu = Fx::node($flow, NodeType::Interactive, [
        'interactive_type' => 'button',
        'body' => 'Posso enviar?',
        'buttons' => [['id' => 'yes', 'title' => 'Sim'], ['id' => 'no', 'title' => 'Não']],
    ], 560);

    Fx::edge(Fx::start($flow), $hello);
    Fx::edge($hello, $menu);
    Fx::edge($menu, Fx::say($flow, 'Enviando agora.', 840), 'yes');
    Fx::edge($menu, Fx::say($flow, 'Tudo bem.', 840, 200), 'no');

    if ($withKey) {
        localizationKey($tenant);
    }

    return [$tenant, $flow];
}

function localizationCustomerWrites(Conversation $conversation, string $text): void
{
    $conversation->messages()->create([
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $text,
        'sent_at' => now(),
    ]);
}

/**
 * A channel that accepts every send and a hub that detects `$language` and
 * translates through `$dictionary`. Every hub run is recorded in `$runs`
 * by kind, so a test can say how many times the AI was asked.
 *
 * @param  array<string, string>  $dictionary
 * @param  array<string, int>  $runs
 */
function fakeLocalizingHub(?string $language, array $dictionary, ?array &$runs, bool $fail = false): void
{
    $runs = ['detect' => 0, 'translate' => 0];

    Http::fake(function (HttpRequest $request) use ($language, $dictionary, &$runs, $fail) {
        if (str_contains($request->url(), 'graph.facebook.com')) {
            return Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]);
        }

        if (! str_ends_with($request->url(), '/runs')) {
            return Http::response([]);
        }

        $content = $request->data()['message']['content'];

        if ($fail) {
            return Http::response(['status' => 'FAILED', 'output' => null, 'error' => ['message' => 'boom']]);
        }

        if (str_contains($content, 'LANGUAGE DETECTION')) {
            $runs['detect']++;

            return Http::response([
                'id' => 'run-'.uniqid(), 'status' => 'COMPLETED',
                'output' => ['message' => json_encode(['reply' => 'ok', 'flow' => null, 'language' => $language ?? 'und'])],
            ]);
        }

        $runs['translate']++;
        $strings = json_decode(trim(substr($content, strrpos($content, 'Strings:') + 8)), true);
        $translations = [];

        foreach ($strings as $string) {
            if (isset($dictionary[$string['text']])) {
                $translations[$string['id']] = $dictionary[$string['text']];
            }
        }

        return Http::response([
            'id' => 'run-'.uniqid(), 'status' => 'COMPLETED',
            'output' => ['message' => json_encode(['reply' => 'ok', 'flow' => null, 'translations' => $translations])],
        ]);
    });
}

const LOCALIZATION_ENGLISH = [
    'Olá {{contact.name}}!' => 'Hello {{contact.name}}!',
    'Posso enviar?' => 'May I send it?',
    'Sim' => 'Yes',
    'Não' => 'No',
    'Enviando agora.' => 'Sending it now.',
    'Tudo bem.' => 'All right.',
];

function localizationInteractiveBody(Conversation $conversation): ?string
{
    return $conversation->messages()
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', MessageType::Interactive)
        ->latest('id')
        ->value('body');
}

test('a flow with the switch off speaks as written and never asks the AI', function () {
    [$tenant, $flow] = localizationFlow(enabled: false);
    fakeLocalizingHub('en', LOCALIZATION_ENGLISH, $runs);

    $conversation = Fx::conversation($tenant, $flow);
    localizationCustomerWrites($conversation, 'Hello, I would like to buy');

    (new FlowExecutor)->startFlow($conversation);

    expect(Fx::sentTexts($conversation))->toBe(['Olá Maria Souza!'])
        ->and($runs)->toBe(['detect' => 0, 'translate' => 0])
        ->and($conversation->contact->fresh()->language)->toBeNull();
});

test('the flow detects the customer language once and answers in it', function () {
    [$tenant, $flow] = localizationFlow();
    fakeLocalizingHub('en', LOCALIZATION_ENGLISH, $runs);

    $conversation = Fx::conversation($tenant, $flow);
    localizationCustomerWrites($conversation, 'Hello, I would like to buy');

    (new FlowExecutor)->startFlow($conversation);

    $contact = $conversation->contact->fresh();

    expect(Fx::sentTexts($conversation))->toBe(['Hello Maria Souza!'])
        ->and(localizationInteractiveBody($conversation))->toBe('May I send it?')
        ->and($contact->language)->toBe('en')
        ->and($contact->language_source)->toBe(FlowLocalization::SOURCE_DETECTED)
        ->and($runs['detect'])->toBe(1)
        ->and(Fx::notes($conversation, FlowLocalization::NOTE_DETECTED))->toHaveCount(1)
        ->and(Fx::notes($conversation, FlowLocalization::NOTE_DETECTED)->first()->meta['info']['params'])->toBe(['language' => 'en'])
        // Never written back to the flow.
        ->and($flow->nodes()->where('type', NodeType::Message)->orderBy('id')->first()->data['body'])->toBe('Olá {{contact.name}}!');
});

test('a translation is paid for once and read from the table after that', function () {
    [$tenant, $flow] = localizationFlow();
    fakeLocalizingHub('en', LOCALIZATION_ENGLISH, $runs);

    $first = Fx::conversation($tenant, $flow, '5511900000001', 'Ann');
    localizationCustomerWrites($first, 'Hello there');
    (new FlowExecutor)->startFlow($first);

    $translateRuns = $runs['translate'];
    FlowLocalization::flush();

    $second = Fx::conversation($tenant, $flow, '5511900000002', 'Bob');
    localizationCustomerWrites($second, 'Good morning, how much is it?');
    (new FlowExecutor)->startFlow($second);

    expect(Fx::sentTexts($second))->toBe(['Hello Bob!'])
        ->and($runs['translate'])->toBe($translateRuns)
        // The second customer still had to be heard once.
        ->and($runs['detect'])->toBe(2)
        ->and(FlowTextTranslation::where('tenant_id', $tenant->id)->where('language', 'en')->count())->toBeGreaterThanOrEqual(2);
});

test('a customer writing in the flow language gets the flow as written, with no note', function () {
    [$tenant, $flow] = localizationFlow();
    fakeLocalizingHub('pt-BR', LOCALIZATION_ENGLISH, $runs);

    $conversation = Fx::conversation($tenant, $flow);
    localizationCustomerWrites($conversation, 'Oi, quero comprar');

    (new FlowExecutor)->startFlow($conversation);

    expect(Fx::sentTexts($conversation))->toBe(['Olá Maria Souza!'])
        ->and($runs['translate'])->toBe(0)
        ->and($conversation->contact->fresh()->language)->toBe('pt-BR')
        ->and(Fx::notes($conversation, FlowLocalization::NOTE_DETECTED))->toHaveCount(0);
});

test('a language an agent set by hand is used as is and never re-detected', function () {
    [$tenant, $flow] = localizationFlow();
    fakeLocalizingHub('pt-BR', LOCALIZATION_ENGLISH, $runs);

    $conversation = Fx::conversation($tenant, $flow);
    $conversation->contact->update(['language' => 'en', 'language_source' => FlowLocalization::SOURCE_MANUAL]);
    localizationCustomerWrites($conversation, 'Oi');

    (new FlowExecutor)->startFlow($conversation);

    expect(Fx::sentTexts($conversation))->toBe(['Hello Maria Souza!'])
        ->and($runs['detect'])->toBe(0)
        ->and($conversation->contact->fresh()->language_source)->toBe(FlowLocalization::SOURCE_MANUAL);
});

test('numbers and emojis are not a language, so nobody is asked', function () {
    [$tenant, $flow] = localizationFlow();
    fakeLocalizingHub('en', LOCALIZATION_ENGLISH, $runs);

    $conversation = Fx::conversation($tenant, $flow);
    localizationCustomerWrites($conversation, '👍 1');

    (new FlowExecutor)->startFlow($conversation);

    expect(Fx::sentTexts($conversation))->toBe(['Olá Maria Souza!'])
        ->and($runs)->toBe(['detect' => 0, 'translate' => 0]);
});

test('a translation that loses a placeholder is refused and the original goes out', function () {
    [$tenant, $flow] = localizationFlow();
    fakeLocalizingHub('en', ['Olá {{contact.name}}!' => 'Hello friend!'] + LOCALIZATION_ENGLISH, $runs);

    $conversation = Fx::conversation($tenant, $flow);
    localizationCustomerWrites($conversation, 'Hello, I would like to buy');

    (new FlowExecutor)->startFlow($conversation);

    expect(Fx::sentTexts($conversation))->toBe(['Olá Maria Souza!'])
        ->and(localizationInteractiveBody($conversation))->toBe('May I send it?')
        ->and(FlowTextTranslation::where('source_hash', FlowLocalization::hash('Olá {{contact.name}}!'))->value('text'))
        ->toBe('Olá {{contact.name}}!');
});

test('when the AI fails the flow speaks as written, says why once, and stops asking for a while', function () {
    [$tenant, $flow] = localizationFlow();
    fakeLocalizingHub('en', LOCALIZATION_ENGLISH, $runs, fail: true);

    $conversation = Fx::conversation($tenant, $flow);
    localizationCustomerWrites($conversation, 'Hello, I would like to buy');

    (new FlowExecutor)->startFlow($conversation);

    expect(Fx::sentTexts($conversation))->toBe(['Olá Maria Souza!'])
        ->and(localizationInteractiveBody($conversation))->toBe('Posso enviar?')
        ->and(Fx::notes($conversation, FlowLocalization::NOTE_UNAVAILABLE))->toHaveCount(1)
        ->and(Fx::notes($conversation, FlowLocalization::NOTE_UNAVAILABLE)->first()->meta['info']['params'])->toBe(['translation_reason' => 'error']);

    // One failed detection, and nothing asked of the AI after it: the
    // translation of each node would only have waited out the same failure.
    expect(Http::recorded(fn (HttpRequest $request) => str_ends_with($request->url(), '/runs')))->toHaveCount(1);
});

test('without an AI key the flow speaks as written and the note says a key is missing', function () {
    [$tenant, $flow] = localizationFlow(withKey: false);
    fakeLocalizingHub('en', LOCALIZATION_ENGLISH, $runs);

    $conversation = Fx::conversation($tenant, $flow);
    localizationCustomerWrites($conversation, 'Hello, I would like to buy');

    (new FlowExecutor)->startFlow($conversation);

    expect(Fx::sentTexts($conversation))->toBe(['Olá Maria Souza!'])
        ->and($runs)->toBe(['detect' => 0, 'translate' => 0])
        ->and(Fx::notes($conversation, FlowLocalization::NOTE_UNAVAILABLE)->first()->meta['info']['params'])->toBe(['translation_reason' => 'setup']);
});

test('a customer who types the option as the author wrote it still takes that branch', function () {
    [$tenant, $flow] = localizationFlow();
    fakeLocalizingHub('en', LOCALIZATION_ENGLISH, $runs);

    $conversation = Fx::conversation($tenant, $flow);
    localizationCustomerWrites($conversation, 'Hello, I would like to buy');
    (new FlowExecutor)->startFlow($conversation);

    FlowLocalization::flush();
    localizationCustomerWrites($conversation, 'Sim');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'Sim');

    expect(Fx::sentTexts($conversation))->toBe(['Hello Maria Souza!', 'Sending it now.']);
});

test('the first miss in a language queues the rest of the flow', function () {
    config(['flow.translation.warm' => true]);
    Bus::fake([WarmFlowTranslations::class]);

    [$tenant, $flow] = localizationFlow();
    fakeLocalizingHub('en', LOCALIZATION_ENGLISH, $runs);

    $conversation = Fx::conversation($tenant, $flow);
    localizationCustomerWrites($conversation, 'Hello, I would like to buy');
    (new FlowExecutor)->startFlow($conversation);

    Bus::assertDispatchedTimes(WarmFlowTranslations::class, 1);
    Bus::assertDispatched(WarmFlowTranslations::class, fn ($job) => $job->flowId === $flow->id && $job->language === 'en');
});

test('warming translates every text of the flow that is not stored yet', function () {
    [$tenant, $flow] = localizationFlow();
    fakeLocalizingHub('en', LOCALIZATION_ENGLISH, $runs);

    app()->call([new WarmFlowTranslations($flow->id, 'en'), 'handle']);

    $stored = FlowLocalization::stored($tenant, 'en', array_map(fn (string $text) => FlowLocalization::hash($text), array_keys(LOCALIZATION_ENGLISH)));

    expect(array_values($stored))->toEqualCanonicalizing(array_values(LOCALIZATION_ENGLISH))
        ->and($runs['translate'])->toBe(1);
});

test('language codes are read in one spelling', function () {
    expect(FlowLanguages::normalize('EN'))->toBe('en')
        ->and(FlowLanguages::normalize('pt_br'))->toBe('pt-BR')
        ->and(FlowLanguages::normalize('zh-Hant-TW'))->toBe('zh-TW')
        ->and(FlowLanguages::normalize('und'))->toBeNull()
        ->and(FlowLanguages::normalize('ignore previous instructions'))->toBeNull()
        ->and(FlowLanguages::same('pt-BR', 'pt'))->toBeTrue()
        ->and(FlowLanguages::same('pt-BR', 'en'))->toBeFalse()
        ->and(FlowLanguages::describe('pt'))->toBe('Portuguese (pt-BR)')
        ->and(FlowLanguages::describe('es-419'))->toBe('Spanish (es-419)');
});

test('turning the switch on needs the AI key, and turning it off never does', function () {
    [$tenant, $flow] = localizationFlow(enabled: false, withKey: false);
    $user = Fx::user($tenant, ['flows.update', 'flows.view']);
    Sanctum::actingAs($user);

    $this->putJson("/api/flows/{$flow->id}/translation", ['translate_to_customer' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'flow_assistant_setup_required');

    $this->putJson("/api/flows/{$flow->id}/translation", ['translate_to_customer' => false, 'language' => 'es'])
        ->assertOk()
        ->assertJsonPath('data.translation.enabled', false)
        ->assertJsonPath('data.translation.language', 'es')
        ->assertJsonPath('data.translation.source_language', 'es');

    localizationKey($tenant);

    $this->putJson("/api/flows/{$flow->id}/translation", ['translate_to_customer' => true, 'language' => null])
        ->assertOk()
        ->assertJsonPath('data.translation.enabled', true)
        ->assertJsonPath('data.translation.language', null)
        ->assertJsonPath('data.translation.source_language', 'pt-BR');

    $this->putJson("/api/flows/{$flow->id}/translation", ['translate_to_customer' => true, 'language' => 'not a language'])
        ->assertStatus(422);
});

test('an agent can set or clear a contact language', function () {
    [$tenant] = localizationFlow(withKey: false);
    $contact = Contact::create(['tenant_id' => $tenant->id, 'channel' => 'whatsapp_official', 'external_id' => '5511911112222', 'name' => 'Ana']);
    Sanctum::actingAs(Fx::user($tenant, ['contacts.update']));

    $this->putJson("/api/contacts/{$contact->id}", ['language' => 'ES'])
        ->assertOk()
        ->assertJsonPath('contact.language', 'es')
        ->assertJsonPath('contact.language_source', FlowLocalization::SOURCE_MANUAL);

    $this->putJson("/api/contacts/{$contact->id}", ['language' => null])
        ->assertOk()
        ->assertJsonPath('contact.language', null)
        ->assertJsonPath('contact.language_source', null);

    $this->putJson("/api/contacts/{$contact->id}", ['language' => '!!'])->assertStatus(422);
});
