<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\DownloadInboundMedia;
use App\Models\AiHubAgent;
use App\Models\AiHubApiKey;
use App\Models\AiHubRun;
use App\Models\AiHubTenant;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A WhatsApp connection parked on an AIAgent node, with a hub agent behind it.
 *
 * @return array{0: Conversation, 1: FlowNode}
 */
function aiAgentFlowFixture(): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $hubTenant = AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'hub_tenant_id' => 'hub-tenant-1',
        'external_id' => 'Pingly_1',
        'name' => 'Pingly_1',
        'status' => 'ACTIVE',
    ]);

    AiHubApiKey::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_api_key_id' => 'hub-key-1',
        'name' => 'default',
        'api_key' => 'tenant-api-key',
        'status' => 'ACTIVE',
    ]);

    $agent = AiHubAgent::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_agent_id' => 'hub-agent-1',
        'external_id' => 'agente_atendimento',
        'name' => 'Atendimento',
        'model' => 'gpt-4o-mini',
        'status' => 'ACTIVE',
    ]);

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Suporte']);

    $start = $flow->nodes()->create([
        'type' => NodeType::Start,
        'data' => null,
        'position_x' => 0,
        'position_y' => 0,
    ]);

    $ai = $flow->nodes()->create([
        'type' => NodeType::AIAgent,
        'data' => [
            'ai_hub_agent_id' => $agent->id,
            'welcoming_message' => 'Oi! Como posso ajudar?',
        ],
        'position_x' => 100,
        'position_y' => 0,
    ]);

    FlowEdge::create([
        'source_node_id' => $start->id,
        'target_node_id' => $ai->id,
        'condition_value' => null,
    ]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => [
            'phone_number_id' => '1083508778182246',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
        ],
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'username' => '5511999999999',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'status' => ConversationStatus::Pending,
    ]);

    return [$conversation, $ai];
}

/** Outbound WhatsApp and the hub both answer; the hub's reply is $aiReply. */
function fakeChannelsAndHub(string $aiReply = 'Vi o erro no seu print.', array $extra = []): void
{
    Http::fake(array_merge($extra, [
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT' . uniqid()]]]),
        'api-ia.ipbr.pro/*' => Http::response([
            'id' => 'run_1',
            'status' => 'COMPLETED',
            'output' => ['message' => $aiReply, 'handoff' => false],
        ]),
    ]));
}

/**
 * Get the conversation past the welcome turn — the first interaction is
 * always answered by the canned greeting, never by the AI.
 */
function openWithWelcome(Conversation $conversation): void
{
    $conversation->messages()->create([
        'external_id' => 'wamid.IN0',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Oi',
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->startFlow($conversation);
}

function incomingMedia(
    Conversation $conversation,
    MessageType $type,
    ?string $body,
    ?string $attachment,
    ?AttachmentStatus $status = null
): Message {
    return $conversation->messages()->create([
        'external_id' => 'wamid.' . uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => $type,
        'body' => $body,
        'attachment' => $attachment,
        'attachment_status' => $status,
        'sent_at' => now(),
    ]);
}

/** The run payloads the hub received, in order. */
function hubRuns(): array
{
    $runs = [];

    foreach (Http::recorded() as [$request, $response]) {
        if (str_contains($request->url(), 'api-ia.ipbr.pro')) {
            $runs[] = $request->data();
        }
    }

    return $runs;
}

test('a screenshot the customer sent reaches the agent as an image attachment', function () {
    // The real disk on purpose: a faked one signs URLs differently, and the
    // signature is the whole reason the hub can fetch the file at all. Nothing
    // here writes to it — no bytes are read, only a link is built.
    fakeChannelsAndHub();

    [$conversation] = aiAgentFlowFixture();
    openWithWelcome($conversation);

    incomingMedia($conversation, MessageType::Image, 'olha o erro', 'media/9_abc.png');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'olha o erro');

    $runs = hubRuns();
    expect($runs)->toHaveCount(1);

    $message = $runs[0]['message'];
    $attachment = $message['attachments'][0] ?? null;

    expect($message['content'])->toBe('olha o erro')
        ->and($attachment['type'])->toBe('image')
        ->and($attachment['detail'])->toBe('auto')
        ->and($attachment['name'])->toBe('9_abc.png')
        // The hub fetches this itself, so it has to be a signed link and not a
        // storage path only this app can read.
        ->and($attachment['url'])->toContain('media/9_abc.png')
        ->and($attachment['url'])->toContain('signature=');

    // And the customer got the answer that came back.
    expect(Message::where('sender_type', SenderType::Outgoing)->pluck('body')->all())
        ->toContain('Vi o erro no seu print.');

    // The run row remembers that the agent was given something to look at.
    expect(AiHubRun::first()->metadata['imageAttachments'])->toBe(1);
});

test('a screenshot with no caption is named instead of being sent as an empty turn', function () {
    Storage::fake('local', ['serve' => true]);
    fakeChannelsAndHub();

    [$conversation] = aiAgentFlowFixture();
    openWithWelcome($conversation);

    incomingMedia($conversation, MessageType::Image, null, 'media/10_abc.jpg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $message = hubRuns()[0]['message'];

    // Empty content asks the model to answer silence, and it obliges — badly.
    expect($message['content'])->toBe('[image]')
        ->and($message['attachments'])->toHaveCount(1);
});

test('media the agent cannot look at is announced, and nothing is attached', function () {
    Storage::fake('local', ['serve' => true]);
    fakeChannelsAndHub();

    [$conversation] = aiAgentFlowFixture();
    openWithWelcome($conversation);

    incomingMedia($conversation, MessageType::Audio, null, 'media/11_abc.ogg');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    $message = hubRuns()[0]['message'];

    expect($message['content'])->toBe('[audio]')
        ->and($message)->not->toHaveKey('attachments');
});

test('the turn is held back while the image is still downloading', function () {
    Storage::fake('local', ['serve' => true]);
    fakeChannelsAndHub();

    [$conversation, $node] = aiAgentFlowFixture();
    openWithWelcome($conversation);

    $image = incomingMedia($conversation, MessageType::Image, 'olha o erro', null, AttachmentStatus::Pending);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'olha o erro');

    // Answering now would mean answering blind — the whole point of the
    // message is the picture that has not arrived yet.
    expect(hubRuns())->toBeEmpty();

    // Nothing was consumed, so the same message is still owed an answer.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->state_data["_ai_last_processed_message_id_{$node->id}"])->toBeLessThan($image->id)
        ->and($state->state_data["_ai_turns_{$node->id}"])->toBe(1);
});

test('the finished download releases the turn it was holding', function () {
    Storage::fake('local', ['serve' => true]);

    // Ordered fakes: the media lookup has to answer before the catch-all that
    // stands in for the send endpoint.
    fakeChannelsAndHub(aiReply: 'A autenticação falhou.', extra: [
        'graph.facebook.com/v25.0/999888777' => Http::response(['url' => 'https://cdn.example/erro.png']),
        'cdn.example/*' => Http::response('png-bytes'),
    ]);

    [$conversation] = aiAgentFlowFixture();
    openWithWelcome($conversation);

    $image = incomingMedia($conversation, MessageType::Image, 'olha o erro', null, AttachmentStatus::Pending);
    $image->forceFill(['meta' => [
        'changes' => [[
            'value' => [
                'messages' => [[
                    'type' => 'image',
                    'image' => ['id' => '999888777', 'mime_type' => 'image/png', 'caption' => 'olha o erro'],
                ]],
            ],
        ]],
    ]])->save();

    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'olha o erro');
    expect(hubRuns())->toBeEmpty();

    // This is the only thing that ever comes back for a deferred turn.
    (new DownloadInboundMedia($image))->handle();

    $runs = hubRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['message']['attachments'])->toHaveCount(1)
        ->and($runs[0]['message']['attachments'][0]['url'])->toContain('.png');

    expect(Message::where('sender_type', SenderType::Outgoing)->pluck('body')->all())
        ->toContain('A autenticação falhou.');
});

test('a download that never lands still gets the customer an answer', function () {
    Storage::fake('local', ['serve' => true]);
    fakeChannelsAndHub();

    [$conversation] = aiAgentFlowFixture();
    openWithWelcome($conversation);

    $image = incomingMedia($conversation, MessageType::Image, 'olha o erro', null, AttachmentStatus::Pending);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'olha o erro');
    expect(hubRuns())->toBeEmpty();

    (new DownloadInboundMedia($image))->failed(new RuntimeException('out of attempts'));

    // Blind, but not silent: leaving the customer with no reply at all because
    // a CDN failed is the worse outcome.
    $runs = hubRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['message']['content'])->toBe('olha o erro')
        ->and($runs[0]['message'])->not->toHaveKey('attachments');
});

test('a caption sent as its own message joins the image in a single turn', function () {
    Storage::fake('local', ['serve' => true]);
    fakeChannelsAndHub();

    [$conversation] = aiAgentFlowFixture();
    openWithWelcome($conversation);

    // "Here's the error" and the screenshot are one thought split in two.
    $image = incomingMedia($conversation, MessageType::Image, null, null, AttachmentStatus::Pending);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    incomingMedia($conversation, MessageType::Text, 'esse é o erro que aparece', null);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'esse é o erro que aparece');

    // Still waiting on the picture, so neither half has been answered yet.
    expect(hubRuns())->toBeEmpty();

    $image->forceFill(['attachment' => 'media/12_abc.png', 'attachment_status' => null])->save();
    (new FlowExecutor)->resumeAfterMedia($image->fresh());

    $runs = hubRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['message']['content'])->toBe("[image]\nesse é o erro que aparece")
        ->and($runs[0]['message']['attachments'])->toHaveCount(1);
});

test('a hub that refuses the images still answers the text', function () {
    Storage::fake('local', ['serve' => true]);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
        'api-ia.ipbr.pro/*' => Http::sequence()
            ->push(['message' => ['model does not support image input']], 400)
            ->push([
                'id' => 'run_2',
                'status' => 'COMPLETED',
                'output' => ['message' => 'Pode descrever o erro?', 'handoff' => false],
            ]),
    ]);

    [$conversation] = aiAgentFlowFixture();
    openWithWelcome($conversation);

    incomingMedia($conversation, MessageType::Image, 'olha o erro', 'media/13_abc.png');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'olha o erro');

    // Whether the agent's model accepts images is decided hub-side, where this
    // app cannot see it. Dropping the pictures beats dropping the customer.
    $runs = hubRuns();
    expect($runs)->toHaveCount(2)
        ->and($runs[0]['message'])->toHaveKey('attachments')
        ->and($runs[1]['message'])->not->toHaveKey('attachments')
        ->and($runs[1])->not->toHaveKey('metadata');

    expect(Message::where('sender_type', SenderType::Outgoing)->pluck('body')->all())
        ->toContain('Pode descrever o erro?');
});
