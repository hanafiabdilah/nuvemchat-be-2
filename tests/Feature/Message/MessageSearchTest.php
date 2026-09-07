<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\MessageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ⚠️ These run on SQLite, where MessageSearch falls back to LIKE. They pin the
 * scoping, the cursor-free contract and the term rules — never the FULLTEXT
 * ranking or word-boundary behaviour, which only exists on MySQL/MariaDB. The
 * boolean expression that production actually sends is asserted directly, as a
 * pure function, at the bottom.
 */
function searchTestUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function searchTestConnection(User $user, string $name = 'WhatsApp'): Connection
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => $name,
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    $user->connections()->syncWithoutDetaching([$connection->id]);

    return $connection;
}

function searchTestConversation(Connection $connection, array $contactAttributes = []): Conversation
{
    $contact = Contact::create(array_merge([
        'tenant_id' => $connection->tenant_id,
        'external_id' => (string) fake()->unique()->numerify('55119########'),
        'name' => 'Ana',
        'channel' => $connection->channel,
    ], $contactAttributes));

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Resolved,
    ]);
}

function searchTestMessage(Conversation $conversation, string $body, array $attributes = []): Message
{
    return $conversation->messages()->create(array_merge([
        'external_id' => 'wamid.'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ], $attributes));
}

it('finds a message in a resolved thread the client never downloaded', function () {
    $user = searchTestUser();
    $conversation = searchTestConversation(searchTestConnection($user));
    $match = searchTestMessage($conversation, 'segue o orcamento do pedido');
    searchTestMessage($conversation, 'obrigado, ate mais');

    $response = $this->actingAs($user)->getJson('/api/messages/search?q=orcamento');

    $response->assertOk();
    expect($response->json('data.*.id'))->toBe([$match->id]);
});

it('requires every term, not just one of them', function () {
    $user = searchTestUser();
    $conversation = searchTestConversation(searchTestConnection($user));
    $both = searchTestMessage($conversation, 'o orcamento do pedido segue anexo');
    searchTestMessage($conversation, 'o orcamento chega amanha');

    $response = $this->actingAs($user)->getJson('/api/messages/search?q=orcamento+pedido');

    expect($response->json('data.*.id'))->toBe([$both->id]);
});

it('answers a too-short query with nothing and says what the rule is', function () {
    $user = searchTestUser();
    searchTestMessage(searchTestConversation(searchTestConnection($user)), 'oi tudo bem');

    $response = $this->actingAs($user)->getJson('/api/messages/search?q=oi');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
    expect($response->json('min_term_length'))->toBe(MessageSearch::MIN_TERM_LENGTH);
});

it('never returns a message from a connection the agent was not given', function () {
    $user = searchTestUser();
    $mine = searchTestConnection($user, 'Mine');

    // Same tenant, but this agent holds no connection_user row for it.
    $theirs = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::Telegram,
        'name' => 'Theirs',
        'color' => '#3b82f6',
        'status' => ConnectionStatus::Active,
    ]);

    $reachable = searchTestMessage(searchTestConversation($mine), 'orcamento aprovado');
    searchTestMessage(searchTestConversation($theirs), 'orcamento aprovado');

    $response = $this->actingAs($user)->getJson('/api/messages/search?q=orcamento');

    expect($response->json('data.*.id'))->toBe([$reachable->id]);
});

it('excludes removed groups, exactly like the inbox does', function () {
    $user = searchTestUser();
    $connection = searchTestConnection($user);

    $removed = searchTestConversation($connection, [
        'is_group' => true,
        'group_removed_at' => now(),
    ]);
    searchTestMessage($removed, 'orcamento aprovado');

    $response = $this->actingAs($user)->getJson('/api/messages/search?q=orcamento');

    expect($response->json('data'))->toBe([]);
});

it('excludes messages that were unsent', function () {
    $user = searchTestUser();
    $conversation = searchTestConversation(searchTestConnection($user));
    searchTestMessage($conversation, 'orcamento aprovado', ['unsend_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/messages/search?q=orcamento');

    expect($response->json('data'))->toBe([]);
});

it('can be narrowed to one connection', function () {
    $user = searchTestUser();
    $a = searchTestConnection($user, 'A');
    $b = searchTestConnection($user, 'B');

    searchTestMessage(searchTestConversation($a), 'orcamento aprovado');
    $wanted = searchTestMessage(searchTestConversation($b), 'orcamento aprovado');

    $response = $this->actingAs($user)
        ->getJson('/api/messages/search?q=orcamento&connection_id='.$b->id);

    expect($response->json('data.*.id'))->toBe([$wanted->id]);
});

it('drops punctuation instead of letting it reach the boolean parser', function () {
    // Every boolean-mode operator is a separator to the tokenizer, so a query
    // made of them carries no terms at all — the case that would otherwise be
    // a syntax error from the database.
    expect(MessageSearch::terms('+-*"~<>()@'))->toBe([]);
    expect(MessageSearch::terms('"orcamento" -pedido'))->toBe(['orcamento', 'pedido']);
});

it('sends every term as required and open-ended', function () {
    expect(MessageSearch::booleanExpression(['orcamento', 'pedido']))
        ->toBe('+orcamento* +pedido*');
});

it('drops terms shorter than the index can answer for', function () {
    expect(MessageSearch::terms('pedido 12 de hoje'))->toBe(['pedido', 'hoje']);
});
