<?php

use App\Models\AiHubAgent;
use App\Models\AiHubRun;
use App\Models\AiHubTenant;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\Support\AiAgentFixtures;
use Tests\Support\InstagramFixtures;
use Tests\Support\YoutubeFixtures;

uses(RefreshDatabase::class);

/**
 * "Responder com IA" on a comment: a draft for the reply box, written by the
 * account's AI agent from what the person is looking at. Nothing is posted.
 */
function commentDraftAgent(User $user): AiHubAgent
{
    $hubTenant = AiHubTenant::create([
        'tenant_id' => $user->tenant_id,
        'hub_tenant_id' => 'hub-tenant-1',
        'external_id' => 'Pingly_1',
        'name' => 'Pingly_1',
        'status' => 'ACTIVE',
    ]);

    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

    return AiHubAgent::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_agent_id' => 'hub-agent-1',
        'external_id' => 'agente_comentarios',
        'name' => 'Comentários',
        'model' => 'gpt-4o-mini',
        'status' => 'ACTIVE',
    ]);
}

function commentDraftHub(string $reply = '"Oi! Temos sim, te chamei no direct 💛"'): void
{
    Http::fake([
        'api-ia.ipbr.pro/*' => Http::response([
            'id' => 'run_1',
            'status' => 'COMPLETED',
            'output' => ['message' => $reply, 'handoff' => false],
        ]),
    ]);
}

function commentDraftBody(array $overrides = []): array
{
    return array_merge([
        'text' => 'Tem em azul? Quanto custa?',
        'author' => '@cliente',
        'post' => [
            'caption' => 'Vestido novo da coleção de primavera',
            'image_url' => 'https://scontent-gru2-1.cdninstagram.com/v/t51/abc.jpg?stp=1',
        ],
        'thread' => [
            ['author' => 'loja.oficial', 'text' => 'Oi! Qual tamanho?', 'from_account' => true],
            ['author' => 'cliente', 'text' => 'M', 'from_account' => false],
        ],
    ], $overrides);
}

test('the account agent drafts a reply from the post, the comment and its thread', function () {
    commentDraftHub();

    $user = InstagramFixtures::user();
    $agent = commentDraftAgent($user);
    $connection = InstagramFixtures::connection($user, ['ai_suggest_agent_id' => $agent->id]);

    $this->actingAs($user)
        ->postJson("/api/social/instagram/accounts/{$connection->id}/comments/c-1/ai-draft", commentDraftBody())
        ->assertOk()
        // The quotes a model wraps a one-liner in are not part of the reply.
        ->assertJsonPath('data.draft', 'Oi! Temos sim, te chamei no direct 💛');

    $run = AiAgentFixtures::hubRuns()[0];
    $content = $run['message']['content'];

    expect($content)->toContain('public comment on Instagram')
        ->toContain('Vestido novo da coleção de primavera')
        ->toContain('Comment by @cliente: Tem em azul? Quanto custa?')
        ->toContain('- Your account: Oi! Qual tamanho?')
        ->toContain('- cliente: M')
        // A hand-off detector reads this string; our own words must not trip it.
        ->not->toContain('human');

    expect($run['message']['attachments'][0]['url'])->toBe('https://scontent-gru2-1.cdninstagram.com/v/t51/abc.jpg?stp=1');
    expect($run['conversation']['externalId'])->toStartWith('comment-draft-');

    $row = AiHubRun::first();
    expect($row->conversation_id)->toBeNull();
    expect($row->metadata['purpose'] ?? null)->toBe('comment_draft');
});

test('a picture from anywhere but the network is not handed to the hub', function () {
    commentDraftHub('Obrigada!');

    $user = InstagramFixtures::user();
    $agent = commentDraftAgent($user);
    $connection = InstagramFixtures::connection($user, ['ai_suggest_agent_id' => $agent->id]);

    $this->actingAs($user)
        ->postJson("/api/social/instagram/accounts/{$connection->id}/comments/c-1/ai-draft", commentDraftBody([
            'post' => ['caption' => 'x', 'image_url' => 'https://evil.example.com/cdninstagram.com.jpg'],
        ]))
        ->assertOk();

    expect(AiAgentFixtures::hubRuns()[0]['message'])->not->toHaveKey('attachments');
});

test('a model that cannot look at the picture still writes the draft', function () {
    Http::fake([
        'api-ia.ipbr.pro/*' => Http::sequence()
            ->push(['message' => ['message.attachments.0.type must be one of the following values: none']], 400)
            ->push(['id' => 'run_2', 'status' => 'COMPLETED', 'output' => ['message' => 'Temos sim!']]),
    ]);

    $user = InstagramFixtures::user();
    $agent = commentDraftAgent($user);
    $connection = InstagramFixtures::connection($user, ['ai_suggest_agent_id' => $agent->id]);

    $this->actingAs($user)
        ->postJson("/api/social/instagram/accounts/{$connection->id}/comments/c-1/ai-draft", commentDraftBody())
        ->assertOk()
        ->assertJsonPath('data.draft', 'Temos sim!');

    $runs = AiAgentFixtures::hubRuns();
    expect($runs)->toHaveCount(2);
    expect($runs[1]['message'])->not->toHaveKey('attachments');
});

test('without a linked agent it asks for one, and says whether this person may pick it', function () {
    Http::fake();

    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->postJson("/api/social/instagram/accounts/{$connection->id}/comments/c-1/ai-draft", commentDraftBody())
        ->assertStatus(422)
        ->assertJsonPath('code', 'ai_agent_not_linked')
        ->assertJsonPath('can_link', false);

    Http::assertNothingSent();
});

test('only someone who moderates the comments can ask for a draft', function () {
    Http::fake();

    $user = InstagramFixtures::user(['instagram-posts.view']);
    $agent = commentDraftAgent($user);
    $connection = InstagramFixtures::connection($user, ['ai_suggest_agent_id' => $agent->id]);

    $this->actingAs($user)
        ->postJson("/api/social/instagram/accounts/{$connection->id}/comments/c-1/ai-draft", commentDraftBody())
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a YouTube channel gets its own drafting agent, picked by someone who edits connections', function () {
    $user = YoutubeFixtures::user();
    $account = YoutubeFixtures::account($user);
    $agent = commentDraftAgent($user);

    $this->actingAs($user)
        ->putJson("/api/social/youtube/accounts/{$account->id}/ai-agent", ['agent_id' => $agent->id])
        ->assertForbidden();

    $user->givePermissionTo(Permission::findOrCreate('connections.update', 'web'));

    $this->actingAs($user->fresh())
        ->putJson("/api/social/youtube/accounts/{$account->id}/ai-agent", ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.agent.name', 'Comentários');

    expect($account->fresh()->ai_suggest_agent_id)->toBe($agent->id);

    commentDraftHub('Valeu pelo comentário!');

    $this->actingAs($user->fresh())
        ->postJson("/api/social/youtube/accounts/{$account->id}/comments/th-1/ai-draft", [
            'text' => 'Ótimo vídeo!',
            'author' => '@ClienteFiel',
            'post' => ['caption' => 'Como escolher o tamanho', 'image_url' => 'https://i.ytimg.com/vi/abc/hqdefault.jpg'],
        ])
        ->assertOk()
        ->assertJsonPath('data.draft', 'Valeu pelo comentário!');

    expect(AiAgentFixtures::hubRuns()[0]['message']['content'])->toContain('public comment on YouTube');
});

test("an agent from another workspace cannot be linked", function () {
    $user = YoutubeFixtures::user();
    $user->givePermissionTo(Permission::findOrCreate('connections.update', 'web'));
    $account = YoutubeFixtures::account($user);

    $stranger = InstagramFixtures::user();
    $theirs = commentDraftAgent($stranger);

    $this->actingAs($user->fresh())
        ->putJson("/api/social/youtube/accounts/{$account->id}/ai-agent", ['agent_id' => $theirs->id])
        ->assertNotFound();

    expect($account->fresh()->ai_suggest_agent_id)->toBeNull();
});
