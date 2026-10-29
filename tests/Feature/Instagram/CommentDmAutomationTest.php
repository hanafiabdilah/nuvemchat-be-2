<?php

use App\Enums\Flow\NodeType;
use App\Enums\Message\SenderType;
use App\Models\CommentDmAutomation;
use App\Models\CommentDmRun;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Services\Connection\Meta\InstagramConfig;
use App\Services\Instagram\CommentDm\CommentDmAutomations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstagramFixtures;

uses(RefreshDatabase::class);

/**
 * "Comentário → Direct": a comment that matches a rule is answered with a
 * private message (filed in the inbox) and, optionally, a public reply.
 */
const CDM_ACCOUNT_USER_ID = '9876543210';

function cdmFakeInstagram(array $dm = ['recipient_id' => 'igsid-555', 'message_id' => 'mid.dm1'], int $dmStatus = 200): void
{
    Http::fake([
        'graph.instagram.com/v25.0/me/messages' => Http::response($dm, $dmStatus),
        'graph.instagram.com/v25.0/*/replies' => Http::response(['id' => 'reply-1']),
        'graph.instagram.com/*' => Http::response([]),
    ]);
}

function cdmPost(object $test, array $payload)
{
    Setting::set(InstagramConfig::KEY_CLIENT_SECRET, 'ig-test-secret');
    $body = json_encode($payload);

    return $test->call('POST', '/webhook/instagram', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'ig-test-secret'),
    ], $body);
}

function cdmComment(array $value = []): array
{
    return [
        'object' => 'instagram',
        'entry' => [[
            'id' => CDM_ACCOUNT_USER_ID,
            'time' => 1790000000,
            'changes' => [[
                'field' => 'comments',
                'value' => array_merge([
                    'from' => ['id' => '555', 'username' => 'maria.c'],
                    'media' => ['id' => 'media-1', 'media_product_type' => 'FEED'],
                    'id' => 'comment-1',
                    'text' => 'EU QUERO o link!',
                ], $value),
            ]],
        ]],
    ];
}

function cdmRule(Connection $connection, array $overrides = []): CommentDmAutomation
{
    return CommentDmAutomation::create(array_merge([
        'tenant_id' => $connection->tenant_id,
        'connection_id' => $connection->id,
        'name' => 'Link da promoção',
        'enabled' => true,
        'media_ids' => null,
        'match_mode' => CommentDmAutomation::MATCH_KEYWORDS,
        'keywords' => ['eu quero', 'link'],
        'public_replies' => ['@{{username}} te chamei no direct! 💛'],
        'dm_message' => 'Oi {{username}}! Aqui está o link: https://loja.example/promo',
    ], $overrides));
}

function cdmSent(string $path): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), $path))
        ->map(fn ($pair) => $pair[0])
        ->values()
        ->all();
}

test('a matching comment gets a DM in the inbox and a public reply under it', function () {
    cdmFakeInstagram();
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    $rule = cdmRule($connection);

    cdmPost($this, cdmComment())->assertOk();

    $dm = cdmSent('/me/messages');
    expect($dm)->toHaveCount(1);
    expect($dm[0]->data()['recipient'])->toBe(['comment_id' => 'comment-1']);
    expect($dm[0]->data()['message']['text'])->toBe('Oi maria.c! Aqui está o link: https://loja.example/promo');

    $reply = cdmSent('/comment-1/replies');
    expect($reply)->toHaveCount(1);
    expect($reply[0]->data()['message'])->toBe('@maria.c te chamei no direct! 💛');

    $run = CommentDmRun::sole();
    expect($run->status)->toBe(CommentDmRun::STATUS_SENT);
    expect($run->automation_id)->toBe($rule->id);
    expect($run->public_reply_id)->toBe('reply-1');

    $conversation = Conversation::findOrFail($run->conversation_id);
    expect($conversation->external_id)->toBe('igsid-555');
    expect($conversation->contact->username)->toBe('maria.c');

    $message = Message::sole();
    expect($message->sender_type)->toBe(SenderType::Outgoing);
    expect($message->external_id)->toBe('mid.dm1');
    expect($message->meta['sent_via'])->toBe(CommentDmAutomations::VIA);

    // Instagram echoes the DM back: it lands on the same row, not a second one.
    cdmPost($this, [
        'object' => 'instagram',
        'entry' => [[
            'id' => CDM_ACCOUNT_USER_ID,
            'messaging' => [[
                'sender' => ['id' => CDM_ACCOUNT_USER_ID],
                'recipient' => ['id' => 'igsid-555'],
                'timestamp' => 1790000001000,
                'message' => ['mid' => 'mid.dm1', 'text' => $message->body, 'is_echo' => true],
            ]],
        ]],
    ])->assertOk();

    expect(Message::count())->toBe(1);
    expect(Message::sole()->meta['sent_via'])->toBe(CommentDmAutomations::VIA);
});

test('the commenter answering the DM gets the connection flow', function () {
    cdmFakeInstagram();
    $user = InstagramFixtures::user();

    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Boas-vindas']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $hello = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Que bom te ver por aqui!', 'message_type' => 'text'],
        'position_x' => 100,
        'position_y' => 0,
    ]);
    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $hello->id, 'condition_value' => null]);

    $connection = InstagramFixtures::connection($user, ['flow_id' => $flow->id]);
    cdmRule($connection);

    cdmPost($this, cdmComment())->assertOk();
    $conversation = Conversation::sole();
    expect(FlowState::where('conversation_id', $conversation->id)->exists())->toBeFalse();

    cdmPost($this, [
        'object' => 'instagram',
        'entry' => [[
            'id' => CDM_ACCOUNT_USER_ID,
            'messaging' => [[
                'sender' => ['id' => 'igsid-555'],
                'recipient' => ['id' => CDM_ACCOUNT_USER_ID],
                // After the DM, as it is in life: an earlier answer would open
                // the flow through the "phone answered later" rule instead.
                'timestamp' => now()->addMinute()->valueOf(),
                'message' => ['mid' => 'mid.in1', 'text' => 'Oi! Quero sim'],
            ]],
        ]],
    ])->assertOk();

    expect(Conversation::count())->toBe(1);
    expect(FlowState::where('conversation_id', $conversation->id)->exists())->toBeTrue();
});

test('comments that match nothing, replies and the account itself are left alone', function () {
    cdmFakeInstagram();
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    cdmRule($connection);

    cdmPost($this, cdmComment(['text' => 'Lindo demais', 'id' => 'c-a']))->assertOk();
    cdmPost($this, cdmComment(['text' => 'eu quero', 'id' => 'c-b', 'parent_id' => 'comment-1']))->assertOk();
    cdmPost($this, cdmComment(['text' => 'link na bio', 'id' => 'c-c', 'from' => ['id' => '17841400000000000', 'username' => 'loja.oficial']]))->assertOk();
    // "link" is a word, not a fragment: LinkedIn is not a request for one.
    cdmPost($this, cdmComment(['text' => 'me segue no linkedin', 'id' => 'c-d']))->assertOk();

    expect(cdmSent('/me/messages'))->toBeEmpty();
    expect(CommentDmRun::count())->toBe(0);
});

test('keywords ignore case and accents but not word boundaries', function () {
    expect(CommentDmAutomations::containsKeyword('Eu quéro!!', ['EU QUERO']))->toBeTrue();
    expect(CommentDmAutomations::containsKeyword('QUANTO custa?', ['quanto']))->toBeTrue();
    expect(CommentDmAutomations::containsKeyword('quantos?', ['quanto']))->toBeFalse();
    expect(CommentDmAutomations::containsKeyword('amei 🔥🔥', ['🔥']))->toBeTrue();
});

test('a repeated delivery sends one DM, and a second comment by the same person on the post none', function () {
    cdmFakeInstagram();
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    cdmRule($connection);

    cdmPost($this, cdmComment())->assertOk();
    cdmPost($this, cdmComment())->assertOk();
    cdmPost($this, cdmComment(['id' => 'comment-2', 'text' => 'eu quero também']))->assertOk();

    expect(cdmSent('/me/messages'))->toHaveCount(1);
    expect(CommentDmRun::where('comment_id', 'comment-2')->value('reason'))->toBe('already_messaged');
});

test('a refused DM is recorded and nothing is posted publicly', function () {
    cdmFakeInstagram(['error' => ['message' => 'This message is sent outside of allowed window.', 'code' => 10, 'error_subcode' => 2534022]], 400);
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    cdmRule($connection);

    cdmPost($this, cdmComment())->assertOk();

    $run = CommentDmRun::sole();
    expect($run->status)->toBe(CommentDmRun::STATUS_FAILED);
    expect($run->error)->not->toBeEmpty()->not->toContain('allowed window');
    expect(cdmSent('/replies'))->toBeEmpty();
    expect(Message::count())->toBe(0);
});

test('a rule for specific posts wins over one for every post', function () {
    cdmFakeInstagram();
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    cdmRule($connection, ['name' => 'Todos', 'match_mode' => CommentDmAutomation::MATCH_ANY, 'keywords' => null, 'dm_message' => 'Geral']);
    $specific = cdmRule($connection, ['name' => 'Promo', 'media_ids' => ['media-1'], 'dm_message' => 'Promo']);

    cdmPost($this, cdmComment())->assertOk();

    expect(CommentDmRun::sole()->automation_id)->toBe($specific->id);
    expect(cdmSent('/me/messages')[0]->data()['message']['text'])->toBe('Promo');
});

test('saving a rule subscribes the account to comments; a rule with keyword mode needs one', function () {
    Http::fake(['graph.instagram.com/*' => Http::response(['success' => true])]);
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/comment-automations", [
            'name' => 'Link',
            'match_mode' => 'keywords',
            'keywords' => ['  ', ''],
            'dm_message' => 'Oi!',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('keywords');

    $this->actingAs($user)
        ->postJson("/api/instagram/accounts/{$connection->id}/comment-automations", [
            'name' => 'Link',
            'match_mode' => 'keywords',
            'keywords' => ['Eu quero', 'eu quero', 'link'],
            'public_replies' => ['Te chamei no direct!', ''],
            'dm_message' => 'Oi! Segue o link.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.keywords', ['Eu quero', 'link'])
        ->assertJsonPath('data.public_replies', ['Te chamei no direct!'])
        ->assertJsonPath('webhook.subscribed', true);

    $subscribe = cdmSent('/subscribed_apps');
    expect($subscribe)->toHaveCount(1);
    expect($subscribe[0]->data()['subscribed_fields'])->toBe('messages,message_reactions,comments');

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/comment-automations")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('rules are the account moderators\' business only', function () {
    Http::fake();
    $user = InstagramFixtures::user(['instagram-posts.view']);
    $connection = InstagramFixtures::connection($user);

    $this->actingAs($user)
        ->getJson("/api/instagram/accounts/{$connection->id}/comment-automations")
        ->assertForbidden();

    $other = InstagramFixtures::user();

    $this->actingAs($other)
        ->getJson("/api/instagram/accounts/{$connection->id}/comment-automations")
        ->assertNotFound();
});
