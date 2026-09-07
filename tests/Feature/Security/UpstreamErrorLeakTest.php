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
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

/**
 * Nothing an outside system says reaches a tenant.
 *
 * Two halves, and both matter. The dictionaries turn the failures we recognise
 * into an instruction someone can follow; the send path proves the ones we do
 * not recognise still leave nothing quotable behind. A translation layer that
 * only covers the cases it was written for is not a guarantee — it is a list.
 */
uses(RefreshDatabase::class);

function leakTestUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    // Owner, so Conversation::visibleTo() does not need a connection_user row.
    $user->assignRole(Role::findOrCreate('owner', 'web'));

    return $user->fresh();
}

function leakTestConversation(User $user): Conversation
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'Vendas',
        'color' => '#25D366',
        'status' => ConnectionStatus::Active,
        'credentials' => ['phone_number_id' => 'phone-1', 'access_token' => 'wa-token'],
    ]);

    $contact = Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => '5511999990001',
        'channel' => Channel::WhatsappOfficial,
        'name' => 'Cliente',
    ]);

    $conversation = Conversation::create([
        'connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'user_id' => $user->id,
        'external_id' => '5511999990001',
        'status' => ConversationStatus::Active,
    ]);

    // The 24h window guard measures from an inbound message.
    Message::create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid.in',
        'body' => 'oi',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'sent_at' => now()->timestamp,
    ]);

    return $conversation->fresh();
}

test('a Graph refusal never reaches the agent, only the instruction behind it', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => [
            'message' => '(#131047) Re-engagement message: Message failed to send because more than 24 hours have passed',
            'code' => 131047,
            'fbtrace_id' => 'AbCdEf123',
        ],
    ], 400)]);

    $user = leakTestUser();
    $conversation = leakTestConversation($user);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/send-message", ['message' => 'olá']);

    $body = $response->json();

    expect($body['message'])
        ->not->toContain('131047')
        ->not->toContain('Re-engagement')
        ->not->toContain('fbtrace')
        ->toContain('24 horas');
    expect($body['code'])->toBe('messaging_window_closed');
    // The reference is what makes the log line findable from a support ticket.
    expect($body['ref'])->toHaveLength(8);
});

test('an unrecognised channel failure leaves nothing quotable behind', function () {
    // Deliberately a shape no dictionary entry matches: the guarantee has to
    // hold for the failures nobody has written an entry for yet.
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['message' => 'Ошибка: internal replica desync at graph.facebook.com/v25.0'],
    ], 500)]);

    $user = leakTestUser();
    $conversation = leakTestConversation($user);

    $body = $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/send-message", ['message' => 'olá'])
        ->json();

    expect($body['message'])
        ->not->toContain('replica desync')
        ->not->toContain('graph.facebook.com');
});

test('the upstream sentence survives in the log, where it is useful', function () {
    Log::spy();

    UpstreamError::message(
        UpstreamProvider::AiHub,
        'provider must be one of the following values: OPENAI, ANTHROPIC',
        upstreamCode: 'bad_request',
        status: 400,
    );

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context) => str_contains($message, 'Upstream failure translated')
            && $context['upstream_message'] === 'provider must be one of the following values: OPENAI, ANTHROPIC'
            && $context['provider'] === 'ai_hub'
            && strlen($context['ref']) === 8
    );
});

test('a provider auth failure is never relayed as 401, which signs the agent out', function () {
    // The SPA logs out on any 401. An expired Meta token answered with one used
    // to end an agent's shift.
    foreach ([401, 403] as $upstream) {
        $translated = UpstreamError::exception(UpstreamProvider::Meta, 'Session has expired', status: $upstream);

        expect($translated->httpStatus)->not->toBe(401);
    }
});

test('our own wording is kept, not flattened, when the exception is already ours', function () {
    $ours = new App\Exceptions\UserFacingException(
        'Two-step verification is still enabled on this number. Disable it at your current provider.'
    );

    expect(UpstreamError::messageFrom(UpstreamProvider::Meta, $ours))
        ->toBe('Two-step verification is still enabled on this number. Disable it at your current provider.');
});

test('the safety net scrubs a leak from a call site nobody has converted yet', function () {
    // Stands in for the next integration's hastily written catch block. The
    // net is not the fix — it is what keeps the guarantee true until the fix
    // lands, and the warning it logs is how the site gets found.
    Route::middleware('api')->get('/api/_leak_probe', fn () => response()->json([
        'message' => 'Client error: OAuthException, fbtrace_id AbC123',
        'errors' => ['provider' => ['provider must be one of the following values: OPENAI']],
    ], 422));

    $body = $this->getJson('/api/_leak_probe')->json();

    expect($body['message'])->not->toContain('OAuthException')
        ->and($body['errors']['provider'][0])->not->toContain('must be one of');
});

test('the safety net leaves the Back Office alone, where the raw text is the point', function () {
    // Platform operators are the people who fix integrations; a translated
    // "não foi possível" would leave them with nothing to act on.
    Route::middleware('api')->get('/api/admin/_leak_probe', fn () => response()->json([
        'message' => 'ProxyBR rejected our partner token: Unauthenticated.',
    ], 502));

    expect($this->getJson('/api/admin/_leak_probe')->json('message'))
        ->toBe('ProxyBR rejected our partner token: Unauthenticated.');
});

test('a successful response is never touched, even when it carries upstream words', function () {
    // A 200 carrying a `message` is data — a chat body, a stored note — and
    // rewriting it would corrupt the product to protect a copy rule.
    Route::middleware('api')->get('/api/_ok_probe', fn () => response()->json([
        'message' => 'o cliente colou: Client error: OAuthException',
    ]));

    expect($this->getJson('/api/_ok_probe')->json('message'))
        ->toContain('OAuthException');
});

test('the fingerprint check finds upstream text but leaves our copy alone', function () {
    expect(UpstreamError::looksExternal('provider must be one of the following values: OPENAI'))->toBeTrue()
        ->and(UpstreamError::looksExternal('(#131047) Re-engagement message'))->toBeTrue()
        ->and(UpstreamError::looksExternal('cURL error 28: Operation timed out'))->toBeTrue()
        ->and(UpstreamError::looksExternal('OAuthException: Session has expired'))->toBeTrue();

    expect(UpstreamError::looksExternal('A janela de 24 horas para responder este contato já fechou.'))->toBeFalse()
        ->and(UpstreamError::looksExternal('Esta conexão já usa essa instância.'))->toBeFalse()
        ->and(UpstreamError::looksExternal('Conversation is not active'))->toBeFalse();
});
