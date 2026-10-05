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
        'api-ia.ipbr.pro/*' => function () use (&$queue) {
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
