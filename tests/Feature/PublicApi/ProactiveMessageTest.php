<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Message\SenderType;
use App\Models\AiProactiveMessage;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiCallbackRef;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ProactiveMessageFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.billing.enforce' => false]);

    // Both ship dark: they add keys to the run payload, and a hub that has not
    // shipped support for them rejects the whole run. Enabled explicitly here
    // so the tests exercise the switched-on behaviour rather than the default.
    config(['ai.proactive.enabled' => true, 'ai.contact_context.enabled' => true]);
});

/** POST as the hub would, with the reference minted for this conversation. */
function push(array $scene, array $body = [], ?string $idempotencyKey = 'evt_1'): \Illuminate\Testing\TestResponse
{
    $headers = ['X-Api-Key' => $scene['key']];

    if ($idempotencyKey !== null) {
        $headers['Idempotency-Key'] = $idempotencyKey;
    }

    return test()->withHeaders($headers)->postJson('/api/v1/conversations/messages', array_merge([
        'callback_ref' => AiCallbackRef::mint(
            $scene['conversation']->id,
            $scene['node']->id,
            $scene['agent']->id,
        ),
        'text' => 'Pronto, Ana! Confirmei que a conta é sua.',
    ], $body));
}

it('writes into the conversation as the agent and leaves it with the AI', function () {
    $scene = F::scenario();

    $response = push($scene)->assertCreated()->assertJsonPath('duplicate', false);

    $message = Message::findOrFail($response->json('message_id'));

    expect($message->conversation_id)->toBe($scene['conversation']->id)
        ->and($message->body)->toBe('Pronto, Ana! Confirmei que a conta é sua.')
        ->and($message->sender_type)->toBe(SenderType::Outgoing)
        // Attribution comes from the reference, never from the request: there
        // is no sender field, and nothing here is a person's work.
        ->and($message->sent_by_ai_hub_agent_id)->toBe($scene['agent']->id)
        ->and($message->sent_by_user_id)->toBeNull()
        ->and($message->meta['ai_generated'])->toBeTrue()
        ->and($message->meta['ai_hub_proactive'])->toBeTrue();

    // The point of the feature: the conversation carries on with the AI.
    expect($scene['conversation']->fresh()->status)->toBe(ConversationStatus::AiHandling);

    // Audit trail, because this is the one surface where text authored outside
    // the platform reaches a customer looking like the business.
    $record = AiProactiveMessage::firstOrFail();
    expect($record->status)->toBe(AiProactiveMessage::STATUS_SENT)
        ->and($record->message_id)->toBe($message->id)
        ->and($record->conversation_id)->toBe($scene['conversation']->id);
});

it('does not let the caller name a conversation it was not given', function () {
    $scene = F::scenario();

    // The original ask was for `conversation_id` — a platform-wide
    // auto-increment. This is the test for why it is not: forging the
    // reference of the next conversation must be impossible, not merely
    // discouraged.
    $forged = AiCallbackRef::mint(
        $scene['conversation']->id + 1,
        $scene['node']->id,
        $scene['agent']->id,
    );

    // Same shape, wrong signature: the body says a different conversation while
    // the MAC still covers the original one.
    [$prefix, $body, $mac] = explode('.', $forged);
    $tampered = $prefix.'.'.$body.'.'.strrev($mac);

    push($scene, ['callback_ref' => $tampered])
        ->assertForbidden()
        ->assertJsonPath('code', 'callback_ref_invalid');

    expect(Message::where('body', 'like', 'Pronto%')->count())->toBe(0);
});

it('refuses a reference that has lapsed', function () {
    $scene = F::scenario();

    $ref = AiCallbackRef::mint($scene['conversation']->id, $scene['node']->id, $scene['agent']->id);

    // Expired is its own answer: the hub waited too long, which is a different
    // instruction from "you are holding something you were never given".
    $this->travel(25)->hours();

    push($scene, ['callback_ref' => $ref])
        ->assertForbidden()
        ->assertJsonPath('code', 'callback_ref_expired');
});

it('will not spend a reference with another workspace key', function () {
    $scene = F::scenario();

    $stranger = User::factory()->create();
    $strangerTenant = Tenant::create(['user_id' => $stranger->id]);
    $stranger->forceFill(['tenant_id' => $strangerTenant->id])->save();
    [, $strangerKey] = ApiKey::issue($strangerTenant, 'Outro', $stranger);

    $this->withHeaders(['X-Api-Key' => $strangerKey, 'Idempotency-Key' => 'evt_1'])
        ->postJson('/api/v1/conversations/messages', [
            'callback_ref' => AiCallbackRef::mint(
                $scene['conversation']->id,
                $scene['node']->id,
                $scene['agent']->id,
            ),
            'text' => 'Olá',
        ])
        ->assertNotFound()
        ->assertJsonPath('code', 'conversation_not_found');
});

it('stands aside once a person is handling the conversation', function () {
    $scene = F::scenario();

    $scene['conversation']->update(['status' => ConversationStatus::Active]);

    push($scene)->assertStatus(409)->assertJsonPath('code', 'conversation_with_human');

    // A refusal writes nothing and burns no key: the hub may reuse it when the
    // situation changes.
    expect(AiProactiveMessage::count())->toBe(0);
});

it('separates a closed conversation from one a person took over', function () {
    $scene = F::scenario();

    $scene['conversation']->update(['status' => ConversationStatus::Resolved]);

    push($scene)->assertStatus(409)->assertJsonPath('code', 'conversation_closed');
});

it('stops working the moment the flow hands off', function () {
    $scene = F::scenario();

    // What transferToHuman leaves behind. No revocation list is consulted: the
    // reference simply no longer describes a live turn.
    FlowState::where('conversation_id', $scene['conversation']->id)
        ->update(['status' => FlowStateStatus::Stopped]);

    push($scene)->assertStatus(409)->assertJsonPath('code', 'conversation_not_with_ai');
});

it('refuses a reference naming an agent the node no longer uses', function () {
    $scene = F::scenario();

    $node = $scene['node'];
    $node->update(['data' => array_merge($node->data, ['ai_hub_agent_id' => $scene['agent']->id + 99])]);

    push($scene)->assertStatus(409)->assertJsonPath('code', 'conversation_not_with_ai');
});

it('sends once however many times the same event is retried', function () {
    $scene = F::scenario();

    $first = push($scene)->assertCreated();

    $replay = push($scene)->assertOk()
        ->assertJsonPath('duplicate', true)
        ->assertJsonPath('message_id', $first->json('message_id'));

    expect($replay->json('conversation_id'))->toBe($scene['conversation']->id)
        ->and(Message::where('sender_type', SenderType::Outgoing)
            ->where('body', 'like', 'Pronto%')->count())->toBe(1);
});

it('does not let a send whose fate is unknown be retried into a second message', function () {
    $scene = F::scenario();

    // A timeout on our side says nothing about whether WhatsApp took the text,
    // so the record has to survive the failure — deleting it is how one hiccup
    // becomes two messages in somebody's chat. First send times out, second
    // succeeds.
    $conversationId = $scene['conversation']->id;
    $attempts = 0;

    $messages = Mockery::mock(MessageService::class);
    $messages->shouldReceive('sendMessage')->twice()
        ->andReturnUsing(function () use (&$attempts, $conversationId) {
            if (++$attempts === 1) {
                throw new \RuntimeException('cURL error 28: Operation timed out');
            }

            return Message::create([
                'conversation_id' => $conversationId,
                'sender_type' => SenderType::Outgoing,
                'message_type' => \App\Enums\Message\MessageType::Text,
                'body' => 'Pronto, Ana!',
                'sent_at' => now(),
            ]);
        });
    app()->instance(MessageService::class, $messages);

    push($scene)->assertStatus(500);

    expect(AiProactiveMessage::firstOrFail()->status)->toBe(AiProactiveMessage::STATUS_FAILED);

    // The caller's retry is told to hold rather than being allowed through.
    push($scene)->assertStatus(409)->assertJsonPath('code', 'message_in_progress');
    expect($attempts)->toBe(1);

    // …and past the stale window a fresh attempt may proceed, so one timeout
    // cannot make the conversation permanently unwritable.
    $this->travel(6)->minutes();

    push($scene)->assertCreated();
    expect($attempts)->toBe(2);
});

it('treats a different event id as a different message', function () {
    $scene = F::scenario();

    push($scene, [], 'evt_1')->assertCreated();
    push($scene, ['text' => 'Mais uma coisa…'], 'evt_2')->assertCreated();

    expect(AiProactiveMessage::count())->toBe(2);
});

it('insists on the idempotency header', function () {
    $scene = F::scenario();

    push($scene, [], null)
        ->assertStatus(422)
        ->assertJsonValidationErrors('Idempotency-Key');
});

it('rejects empty text and text past the channel limit', function () {
    $scene = F::scenario();

    push($scene, ['text' => '   '])->assertStatus(422)->assertJsonValidationErrors('text');
    push($scene, ['text' => str_repeat('a', 4097)])->assertStatus(422)->assertJsonValidationErrors('text');
});

it('refuses once the WhatsApp session window has closed', function () {
    $scene = F::scenario();

    // The customer's only message drifts out of the 24h window.
    Message::where('conversation_id', $scene['conversation']->id)
        ->where('sender_type', SenderType::Incoming)
        ->update(['sent_at' => now()->subHours(25)->timestamp]);

    push($scene)->assertStatus(422)->assertJsonPath('code', 'messaging_window_closed');

    // Unlike the dashboard's guard, this must not close the conversation as a
    // side effect of a partner's callback.
    expect($scene['conversation']->fresh()->status)->toBe(ConversationStatus::AiHandling);
});

it('caps how often one conversation can be pushed', function () {
    $scene = F::scenario();

    config(['ai.proactive.max_per_conversation_per_hour' => 2]);

    push($scene, [], 'evt_1')->assertCreated();
    push($scene, [], 'evt_2')->assertCreated();
    push($scene, [], 'evt_3')->assertStatus(429)->assertJsonPath('code', 'too_many_proactive_messages');

    RateLimiter::clear("ai-proactive:{$scene['conversation']->id}");
});

it('can be switched off without a deploy', function () {
    $scene = F::scenario();

    config(['ai.proactive.enabled' => false]);

    push($scene)->assertForbidden()->assertJsonPath('code', 'proactive_messages_disabled');
});

it('needs a valid API key like every other public endpoint', function () {
    $scene = F::scenario();

    $this->withHeaders(['X-Api-Key' => 'pk_nope', 'Idempotency-Key' => 'evt_1'])
        ->postJson('/api/v1/conversations/messages', [
            'callback_ref' => AiCallbackRef::mint(
                $scene['conversation']->id,
                $scene['node']->id,
                $scene['agent']->id,
            ),
            'text' => 'Olá',
        ])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'api_key_invalid');
});

it('keeps the proactive message out of the next turn sent to the hub', function () {
    $scene = F::scenario();
    $conversation = $scene['conversation'];

    // A sentence the hub wrote itself, containing the very word its handoff
    // detector watches for. Fed back in the transcript it would hand the
    // conversation to a person on the next turn.
    push($scene, ['text' => 'Se preferir, posso chamar um atendente humano.'])->assertCreated();

    // The control: a flow-authored message, which the transcript exists to
    // carry. Without it this test would pass on an empty transcript, proving
    // nothing about the exclusion.
    $conversation->messages()->create([
        'sender_type' => SenderType::Outgoing,
        'message_type' => \App\Enums\Message\MessageType::Text,
        'body' => 'O servidor de Cipayung está em manutenção.',
        'sent_by_flow_id' => FlowState::where('conversation_id', $conversation->id)->value('flow_id'),
        'sent_at' => now(),
    ]);

    $input = $conversation->messages()->create([
        'sender_type' => SenderType::Incoming,
        'message_type' => \App\Enums\Message\MessageType::Text,
        'body' => 'E agora?',
        'sent_at' => now(),
    ]);

    $transcript = \App\Services\AiAgentHub\AiConversationContext::build(
        Conversation::findOrFail($conversation->id),
        0,
        Message::whereKey($input->id)->get(),
    )[0];

    expect($transcript)->toBeString()
        ->toContain('Cipayung')
        ->not->toContain('atendente humano');
});
