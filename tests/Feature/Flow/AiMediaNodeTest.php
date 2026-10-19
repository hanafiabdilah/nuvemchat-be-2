<?php

use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubTenant;
use App\Models\AiMediaGeneration;
use App\Models\Conversation;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Services\Flow\AiMediaNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HubRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AiAgentFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Storage::fake('local');
    Storage::fake('public');
    config(['ai.media.enabled' => true]);
});

/**
 * start → ai_media → (generated: "Gostou?" | failed: "Vou chamar alguém.").
 *
 * @return array{0: Conversation, 1: FlowNode}
 */
function mediaFlow(array $data = []): array
{
    [$conversation, $node] = AiAgentFixtures::flow();

    $credential = AiHubProviderCredential::create([
        'ai_hub_tenant_id' => AiHubTenant::first()->id,
        'hub_provider_credential_id' => 'hub-cred-9',
        'provider' => 'OPENAI',
        'name' => 'OpenAI',
        'key_preview' => 'sk-…abcd',
        'status' => 'ACTIVE',
    ]);

    $node->update([
        'type' => NodeType::AiMedia,
        'data' => array_merge(AiMediaNodes::defaults(), [
            'provider_credential_id' => $credential->id,
            'prompt' => 'Uma figurinha de álbum para {{contact.name}}',
            'wait_message' => 'Criando sua figurinha…',
            'caption' => 'Aqui está!',
        ], $data),
    ]);

    foreach (['generated' => 'Gostou?', 'failed' => 'Vou chamar alguém.'] as $branch => $body) {
        $target = FlowNode::create([
            'flow_id' => $node->flow_id,
            'type' => NodeType::Message,
            'data' => ['body' => $body, 'message_type' => 'text'],
            'position_x' => 300,
            'position_y' => 0,
        ]);
        FlowEdge::create(['source_node_id' => $node->id, 'target_node_id' => $target->id, 'condition_value' => $branch]);
    }

    return [$conversation, $node->fresh()];
}

/** The hub answers each generation call with the next of `$answers`; the channel accepts every send. */
function fakeMediaHub(array ...$answers): void
{
    $queue = $answers;

    Http::fake([
        'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]], 'id' => 'media-1']),
        'api-ia.ipbr.pro/files/*' => Http::response('generated-bytes', 200, ['Content-Type' => 'image/png']),
        // Http::fake runs every matching callback, so the file download would
        // otherwise use up the next generation's answer.
        'api-ia.ipbr.pro/*' => function (HubRequest $request) use (&$queue) {
            if (str_contains($request->url(), '/files/')) {
                return null;
            }

            return Http::response(array_merge(['id' => 'gen_1', 'provider' => 'OPENAI', 'model' => 'gpt-image-1'], array_shift($queue) ?? ['status' => 'RUNNING']));
        },
    ]);
}

function mediaDone(array $output = []): array
{
    return [
        'status' => 'COMPLETED',
        'providerCostUsd' => 0.04,
        'output' => array_merge(['url' => 'https://api-ia.ipbr.pro/files/out.png', 'mimeType' => 'image/png'], $output),
    ];
}

function mediaCreateRequest(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->first(fn (HubRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/media-generations'))
        ->data();
}

function mediaSent(Conversation $conversation): array
{
    return $conversation->messages()
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')
        ->get()
        ->map(fn ($message) => $message->message_type->value.':'.$message->body)
        ->all();
}

test('a generated image is stored, sent with its caption, and the flow moves on', function () {
    [$conversation] = mediaFlow(['image' => ['size' => '1024x1536', 'quality' => 'medium']]);
    fakeMediaHub(mediaDone());

    AiAgentFixtures::openWithWelcome($conversation);

    $generation = AiMediaGeneration::sole();
    $state = FlowState::where('conversation_id', $conversation->id)->first()->state_data;
    $request = mediaCreateRequest();

    expect(mediaSent($conversation))->toBe(['text:Criando sua figurinha…', 'image:Aqui está!', 'text:Gostou?'])
        ->and($generation->status)->toBe('completed')
        ->and($generation->cost_usd)->toBe(0.04)
        ->and(Storage::disk('public')->get($generation->path))->toBe('generated-bytes')
        ->and($state['ai_media_status'])->toBe('generated')
        ->and($state['ai_media_url'])->toContain('/ai-media/')
        ->and($request['type'])->toBe('image')
        ->and($request['providerCredentialId'])->toBe('hub-cred-9')
        ->and($request['externalId'])->toBe($generation->external_id)
        ->and($request['prompt'])->toBe('Uma figurinha de álbum para Ana')
        ->and($request['image'])->toBe(['size' => '1024x1536', 'quality' => 'medium', 'format' => 'png']);
});

test('the customer\'s photo and the author\'s template are sent as references', function () {
    [$conversation] = mediaFlow([
        'use_customer_image' => true,
        'reference_urls' => ['https://cdn.example.com/figurinha.png', 'http://not-https.example.com/x.png'],
    ]);
    fakeMediaHub(mediaDone());

    Storage::disk('local')->put('media/1/filho.jpg', 'photo');
    AiAgentFixtures::incomingMedia($conversation, MessageType::Image, null, 'media/1/filho.jpg');
    AiAgentFixtures::openWithWelcome($conversation);

    $images = mediaCreateRequest()['inputImages'];

    expect($images)->toHaveCount(2)
        ->and($images[0]['url'])->toContain('filho.jpg')
        ->and($images[1])->toBe(['url' => 'https://cdn.example.com/figurinha.png']);
});

test('a node that needs the customer\'s photo and has none leaves through failed', function () {
    [$conversation] = mediaFlow(['use_customer_image' => true]);
    fakeMediaHub(mediaDone());

    AiAgentFixtures::openWithWelcome($conversation);

    expect(AiMediaGeneration::sole()->error_code)->toBe('no_reference')
        ->and(mediaSent($conversation))->toBe(['text:Criando sua figurinha…', 'text:Vou chamar alguém.'])
        ->and($conversation->messages()->where('meta->info->code', 'flow_ai_media_failed')->count())->toBe(1);
});

test('a generation still running is polled until it is done', function () {
    [$conversation] = mediaFlow(['media_type' => 'video', 'video' => ['seconds' => 8, 'size' => '1280x720']]);
    fakeMediaHub(['status' => 'QUEUED'], ['status' => 'RUNNING'], mediaDone(['mimeType' => 'video/mp4']));

    AiAgentFixtures::openWithWelcome($conversation);

    $generation = AiMediaGeneration::sole();

    expect($generation->status)->toBe('completed')
        ->and($generation->polls)->toBe(2)
        ->and($generation->path)->toEndWith('video.mp4')
        ->and(mediaCreateRequest()['video'])->toBe(['seconds' => 8, 'size' => '1280x720'])
        ->and(mediaSent($conversation))->toContain('video:Aqui está!');
});

test('a refusal by the provider takes the failed branch with its reason', function () {
    [$conversation] = mediaFlow();
    fakeMediaHub(['status' => 'FAILED', 'error' => ['code' => 'content_policy', 'message' => 'Rejected by the safety system.']]);

    AiAgentFixtures::openWithWelcome($conversation);

    $state = FlowState::where('conversation_id', $conversation->id)->first()->state_data;

    expect($state['ai_media_status'])->toBe('failed')
        ->and($state['ai_media_error'])->toBe('content_policy')
        ->and(mediaSent($conversation))->toBe(['text:Criando sua figurinha…', 'text:Vou chamar alguém.']);
});

test('a generation that never finishes times out instead of polling forever', function () {
    config(['ai.media.deadline_seconds.image' => 12, 'ai.media.poll_seconds' => 4]);

    [$conversation] = mediaFlow();
    fakeMediaHub();

    AiAgentFixtures::openWithWelcome($conversation);

    expect(AiMediaGeneration::sole()->only(['status', 'error_code', 'polls']))
        ->toBe(['status' => 'failed', 'error_code' => 'timeout', 'polls' => 3]);
});

test('while the hub has no generation endpoint the node calls nothing and fails closed', function () {
    config(['ai.media.enabled' => false]);

    [$conversation] = mediaFlow();
    fakeMediaHub(mediaDone());

    AiAgentFixtures::openWithWelcome($conversation);

    expect(AiMediaGeneration::count())->toBe(0)
        ->and(mediaSent($conversation))->toBe(['text:Vou chamar alguém.'])
        ->and(FlowState::where('conversation_id', $conversation->id)->first()->state_data['ai_media_error'])->toBe('disabled');

    Http::assertNotSent(fn (HubRequest $request) => str_contains($request->url(), 'media-generations'));
});

test('what the customer writes while the file is being made does not start a second one', function () {
    [$conversation, $node] = mediaFlow();
    fakeMediaHub(['status' => 'RUNNING']);
    config(['ai.media.deadline_seconds.image' => 0]);

    // Parked by hand: a generation in flight that no job is advancing.
    AiAgentFixtures::openWithWelcome($conversation);
    $flowState = FlowState::where('conversation_id', $conversation->id)->first();
    $flowState->update([
        'current_node_id' => $node->id,
        'status' => 'running',
        'state_data' => array_merge($flowState->state_data, [AiMediaNodes::stateKey($node->id) => 999]),
    ]);

    $before = AiMediaGeneration::count();
    $conversation->messages()->create(['external_id' => uniqid(), 'sender_type' => SenderType::Incoming, 'message_type' => MessageType::Text, 'body' => 'e aí?', 'sent_at' => now()]);
    (new App\Services\Flow\FlowExecutor)->resumeFlow($conversation->fresh(), 'e aí?');

    expect(AiMediaGeneration::count())->toBe($before);
});

test('a prompt up to the limit saves, one character more is refused', function () {
    $node = fn (int $length) => [['type' => 'ai_media', 'data' => ['prompt' => str_repeat('a', $length)]]];

    App\Services\Flow\FlowBlueprint::validateNodes($node(AiMediaNodes::MAX_PROMPT_LENGTH));

    expect(AiMediaNodes::MAX_PROMPT_LENGTH)->toBeGreaterThan(10000)
        ->and(fn () => App\Services\Flow\FlowBlueprint::validateNodes($node(AiMediaNodes::MAX_PROMPT_LENGTH + 1)))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('a long prompt reaches the hub whole', function () {
    $prompt = str_repeat('Uma figurinha colorida. ', 450); // 10,800 characters
    [$conversation] = mediaFlow(['prompt' => $prompt]);
    fakeMediaHub(mediaDone());

    AiAgentFixtures::openWithWelcome($conversation);

    expect(mediaCreateRequest()['prompt'])->toBe($prompt)
        ->and(mb_strlen(AiMediaGeneration::sole()->prompt))->toBeGreaterThan(10000);
});

/** Three pictures, each with its own instruction and caption. */
function threeImages(): array
{
    return ['images' => [
        ['prompt' => 'Figurinha um', 'caption' => 'Um'],
        ['prompt' => 'Figurinha dois', 'caption' => 'Dois'],
        ['prompt' => 'Figurinha três', 'caption' => 'Três'],
    ]];
}

/** @return list<array<string, mixed>> */
function mediaCreateRequests(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (HubRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/media-generations'))
        ->map(fn (HubRequest $request) => $request->data())
        ->values()
        ->all();
}

test('several images are all asked for, then sent in order with their own captions', function () {
    [$conversation] = mediaFlow(threeImages());
    fakeMediaHub(mediaDone(), mediaDone(), mediaDone());

    AiAgentFixtures::openWithWelcome($conversation);

    $state = FlowState::where('conversation_id', $conversation->id)->first()->state_data;

    expect(collect(mediaCreateRequests())->pluck('prompt')->all())->toBe(['Figurinha um', 'Figurinha dois', 'Figurinha três'])
        ->and(AiMediaGeneration::pluck('status')->unique()->all())->toBe(['completed'])
        ->and(mediaSent($conversation))->toBe(['text:Criando sua figurinha…', 'image:Um', 'image:Dois', 'image:Três', 'text:Gostou?'])
        ->and($state['ai_media_status'])->toBe('generated')
        ->and($state['ai_media_count'])->toBe(3)
        ->and($state['ai_media_failed_count'])->toBe(0)
        ->and(explode("\n", $state['ai_media_urls']))->toHaveCount(3)
        ->and($state['ai_media_url'])->toBe(explode("\n", $state['ai_media_urls'])[0])
        ->and($state)->not->toHaveKey(AiMediaNodes::stateKey(FlowNode::where('type', NodeType::AiMedia)->value('id')));
});

test('every image is started before any of them is waited on', function () {
    Illuminate\Support\Facades\Queue::fake();
    [$conversation, $node] = mediaFlow(threeImages());

    AiAgentFixtures::openWithWelcome($conversation);

    $ids = AiMediaGeneration::orderBy('id')->pluck('id')->all();

    expect($ids)->toHaveCount(3)
        ->and(FlowState::where('conversation_id', $conversation->id)->first()->state_data[AiMediaNodes::stateKey($node->id)]['ids'])->toBe($ids);

    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\GenerateFlowMedia::class, 3);
});

test('an image that finishes early waits for the ones before it, so the order holds', function () {
    Illuminate\Support\Facades\Queue::fake();
    [$conversation] = mediaFlow(threeImages());
    AiAgentFixtures::openWithWelcome($conversation);
    [$first, $second, $third] = AiMediaGeneration::orderBy('id')->pluck('id')->all();
    fakeMediaHub(mediaDone(), mediaDone(), mediaDone());

    $images = fn () => array_values(array_filter(mediaSent($conversation), fn (string $sent) => str_starts_with($sent, 'image:')));

    (new App\Services\Flow\FlowExecutor)->runAiMedia($third);
    (new App\Services\Flow\FlowExecutor)->runAiMedia($second);

    expect($images())->toBe([])
        ->and(AiMediaGeneration::whereIn('id', [$second, $third])->pluck('status')->unique()->all())->toBe(['completed']);

    (new App\Services\Flow\FlowExecutor)->runAiMedia($first);

    expect($images())->toBe(['image:Um', 'image:Dois', 'image:Três'])
        ->and(FlowState::where('conversation_id', $conversation->id)->first()->state_data['ai_media_status'])->toBe('generated');
});

test('when some images fail, the ones that worked are sent and the flow takes failed', function () {
    [$conversation] = mediaFlow(threeImages());
    fakeMediaHub(
        mediaDone(),
        ['status' => 'FAILED', 'error' => ['code' => 'content_policy', 'message' => 'Rejected by the safety system.']],
        mediaDone(),
    );

    AiAgentFixtures::openWithWelcome($conversation);

    $state = FlowState::where('conversation_id', $conversation->id)->first()->state_data;
    $note = $conversation->messages()->where('meta->info->code', 'flow_ai_media_failed_some')->sole();

    expect(mediaSent($conversation))->toBe(['text:Criando sua figurinha…', 'image:Um', 'image:Três', 'text:Vou chamar alguém.'])
        ->and($state['ai_media_status'])->toBe('failed')
        ->and($state['ai_media_error'])->toBe('content_policy')
        ->and($state['ai_media_count'])->toBe(2)
        ->and($state['ai_media_failed_count'])->toBe(1)
        ->and($note->meta['info']['params'])->toMatchArray(['failed' => 1, 'total' => 3, 'media_reason' => 'content_policy']);
});

test('rows without an instruction are skipped, and only an image node reads the list', function () {
    expect(AiMediaNodes::items(['images' => [['prompt' => ' '], ['prompt' => 'Uma'], ['caption' => 'sem instrução']]]))
        ->toBe([['prompt' => 'Uma', 'caption' => '']])
        ->and(AiMediaNodes::items(['media_type' => 'audio', 'prompt' => 'Olá', 'images' => [['prompt' => 'Uma'], ['prompt' => 'Duas']]]))
        ->toBe([['prompt' => 'Olá', 'caption' => '']])
        ->and(AiMediaNodes::items(['prompt' => 'Antiga', 'caption' => 'Legenda']))
        ->toBe([['prompt' => 'Antiga', 'caption' => 'Legenda']]);
});

test('a node may ask for up to ten images', function () {
    $node = fn (int $count) => [['type' => 'ai_media', 'data' => [
        'images' => array_fill(0, $count, ['prompt' => 'Figurinha']),
    ]]];

    App\Services\Flow\FlowBlueprint::validateNodes($node(AiMediaNodes::MAX_IMAGES));

    expect(AiMediaNodes::MAX_IMAGES)->toBe(10)
        ->and(fn () => App\Services\Flow\FlowBlueprint::validateNodes($node(AiMediaNodes::MAX_IMAGES + 1)))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('a node parked on a single generation before the list existed still finishes', function () {
    Illuminate\Support\Facades\Queue::fake();
    [$conversation, $node] = mediaFlow();
    AiAgentFixtures::openWithWelcome($conversation);
    $generation = AiMediaGeneration::sole();

    // The shape a node parked before this change holds: a bare id.
    $flowState = FlowState::where('conversation_id', $conversation->id)->first();
    $flowState->update(['state_data' => array_merge($flowState->state_data, [AiMediaNodes::stateKey($node->id) => $generation->id])]);
    fakeMediaHub(mediaDone());

    (new App\Services\Flow\FlowExecutor)->runAiMedia($generation->id);

    expect(mediaSent($conversation))->toContain('image:Aqui está!')
        ->and($flowState->fresh()->state_data['ai_media_status'])->toBe('generated');
});
