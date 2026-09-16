<?php

use App\Enums\Connection\Channel;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Services\AiAgentHub\AiCallbackRef;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AiAgentFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Both ship dark — see config/ai.php. Turned on here so these tests are
    // about the payload rather than about the default.
    config(['ai.contact_context.enabled' => true, 'ai.proactive.enabled' => true]);
});

/**
 * Get a real turn to the hub and return the payload it received.
 *
 * `$extra` fakes whatever channel `$arrange` switched the connection to: the
 * welcome really is sent, and an unfaked host means the test reaches the
 * internet and waits out a retry.
 */
function contactRun(?callable $arrange = null, array $extra = []): array
{
    [$conversation, $node] = F::flow();

    if ($arrange) {
        $arrange($conversation, $node);
    }

    F::fakeChannelsAndHub(extra: $extra);
    F::openWithWelcome($conversation);

    $conversation->messages()->create([
        'external_id' => 'wamid.IN1',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'meu proxy não conecta',
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'meu proxy não conecta');

    return F::hubRuns()[0] ?? [];
}

it('ships dark, so a deploy cannot arrive before the hub supports the fields', function () {
    // Read from the file rather than the container, which beforeEach has
    // already switched on. The hub rejects a run over one unknown field, so a
    // deploy that lands first would take every AI run on the platform down —
    // for a feature one partner asked for. Both go on by env once the hub
    // confirms.
    $shipped = require base_path('config/ai.php');

    expect($shipped['contact_context']['enabled'])->toBeFalse()
        ->and($shipped['proactive']['enabled'])->toBeFalse();
});

it('states the phone number instead of leaving it to be inferred', function () {
    $payload = contactRun();

    expect($payload['conversation'])
        ->toHaveKey('contactPhone')
        // Digits with the country code, the shape the partner asked for.
        ->and($payload['conversation']['contactPhone'])->toBe('5511999999999')
        // How much the number is worth as evidence — Meta states it itself here.
        ->and($payload['conversation']['phoneSource'])->toBe('meta_wa_id')
        ->and($payload['conversation']['contactDisplayName'])->toBe('Ana')
        // The fields that were always sent are untouched: the hub reads them.
        ->and($payload['conversation']['contactExternalId'])->toBe('5511999999999')
        ->and($payload['conversation']['channel'])->toBe('whatsapp');
});

it('marks a number derived from the unofficial client as such', function () {
    $payload = contactRun(function ($conversation) {
        $conversation->connection->update([
            'channel' => Channel::WhatsappApiway,
            'credentials' => ['instance_id' => 'inst-1', 'token' => 'core-token'],
        ]);
        $conversation->contact->update(['channel' => Channel::WhatsappApiway]);
    }, ['whats-api.ipbr.pro/*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'data' => ['id' => 'core-1']])]);

    // Both are fine for answering a customer; they are not equally strong when
    // the question is whether to release account data, and the hub cannot see
    // the difference from anywhere else.
    expect($payload['conversation']['phoneSource'])->toBe('whatsmeow')
        ->and($payload['conversation']['contactPhone'])->toBe('5511999999999');
});

it('sends no phone at all on a channel that does not address people by phone', function () {
    $payload = contactRun(function ($conversation) {
        $conversation->connection->update([
            'channel' => Channel::Telegram,
            'credentials' => ['token' => 'bot-token'],
        ]);
        // A Telegram chat id that would pass for a Brazilian mobile if anybody
        // treated `contactExternalId` as a number. This is the false positive
        // the typed field exists to prevent: it would match a registered phone
        // and hand over somebody else's subscription.
        $conversation->contact->update([
            'channel' => Channel::Telegram,
            'external_id' => '5511999999999',
        ]);
        $conversation->update(['external_id' => '5511999999999']);
    }, ['api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);

    expect($payload['conversation'])->not->toHaveKey('contactPhone')
        ->and($payload['conversation'])->not->toHaveKey('phoneSource')
        ->and($payload['conversation']['channel'])->toBe('telegram');
});

it('sends no display name when the "name" is really the number', function () {
    $payload = contactRun(function ($conversation) {
        // What a contact that never shared a profile is called.
        $conversation->contact->update(['name' => '5511999999999', 'name_locked' => true]);
    });

    expect($payload['conversation'])->not->toHaveKey('contactDisplayName')
        ->and($payload['conversation']['contactPhone'])->toBe('5511999999999');
});

it('can be switched off entirely, because the hub rejects unknown fields', function () {
    // The August 2026 failure this guards: the hub validates its run DTO
    // strictly, one unknown field rejects the whole run, and the text-only
    // retry does not drop these keys.
    config(['ai.contact_context.enabled' => false]);

    $payload = contactRun();

    expect($payload['conversation'])->not->toHaveKey('contactPhone')
        ->and($payload['conversation'])->not->toHaveKey('contactDisplayName')
        ->and($payload['conversation']['contactExternalId'])->toBe('5511999999999');
});

it('hands the hub a usable way back into this conversation', function () {
    $payload = contactRun();

    expect($payload['conversation'])->toHaveKey('callbackRef');

    $claims = AiCallbackRef::open($payload['conversation']['callbackRef']);

    expect($claims['conversation_id'])->toBeInt()
        ->and($claims['flow_node_id'])->toBeInt()
        ->and($claims['ai_hub_agent_id'])->toBeInt();
});

it('mints no way back for a draft run', function () {
    [$conversation, $node] = F::flow();
    F::fakeChannelsAndHub();
    F::openWithWelcome($conversation);

    $agent = \App\Models\AiHubAgent::findOrFail($node->data['ai_hub_agent_id']);

    // What "Respond with AI" does: a run under a synthetic hub key, so the real
    // conversation's hub history is untouched. A draft must not carry the
    // ability to send.
    app(\App\Services\AiAgentHub\AiAgentHubTenantService::class)->runAgent(
        $agent,
        $conversation->fresh(),
        'rascunho',
        conversationExternalId: 'suggest:1:m2',
    );

    $draft = collect(F::hubRuns())->last();

    expect($draft['conversation']['externalId'])->toBe('suggest:1:m2')
        ->and($draft['conversation'])->not->toHaveKey('callbackRef');
});

it('mints no way back when the capability is off', function () {
    config(['ai.proactive.enabled' => false]);

    $payload = contactRun();

    expect($payload['conversation'])->not->toHaveKey('callbackRef');
});
