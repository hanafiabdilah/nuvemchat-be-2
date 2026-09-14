<?php

use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Source;
use App\Enums\Broadcast\Status;
use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Broadcast\BroadcastSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BroadcastFixtures;

uses(RefreshDatabase::class);

/**
 * Sending one message into threads selected in the inbox, optionally resolving
 * each. It rides the campaign engine, and the queue runs synchronously under
 * test, so one request drives the send to completion.
 */
beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $this->user = BroadcastFixtures::user();
    $this->line = BroadcastFixtures::connection($this->user);
    $this->user->connections()->attach($this->line->id);

    /** An open thread (window open) assigned to the sender unless told otherwise. */
    $this->thread = function (string $number, string $name, array $attributes = [], ?Connection $connection = null, ?int $sentAt = null): Conversation {
        $conversation = BroadcastFixtures::conversationWithInbound(
            $connection ?? $this->line,
            BroadcastFixtures::contact($this->user, $number, $name),
            $sentAt,
        );

        $conversation->forceFill($attributes + ['user_id' => $this->user->id])->save();

        return $conversation->fresh();
    };

    /** What the business said in a thread — chat messages only, not the timeline notes. */
    $this->said = fn (Conversation $conversation): array => Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info->value)
        ->orderBy('id')
        ->pluck('body')
        ->all();

    /** A queued inbox send for one thread, not yet run. */
    $this->queued = function (Conversation $conversation, bool $resolveAfter = true): BroadcastRecipient {
        $broadcast = Broadcast::create([
            'tenant_id' => $this->user->tenant_id,
            'connection_id' => $conversation->connection_id,
            'created_by' => $this->user->id,
            'name' => 'Aviso',
            'status' => Status::Running,
            'source' => Source::Inbox,
            'resolve_after' => $resolveAfter,
            'content_type' => 'text',
            'payload' => ['body' => 'Aviso'],
            'rate_per_minute' => 60,
            'total_recipients' => 1,
        ]);

        return BroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->id,
            'address' => 'thread-' . $conversation->id,
            'status' => RecipientStatus::Pending,
        ]);
    };
});

test('the message goes into each selected thread without opening, reassigning or tagging anything', function () {
    $ana = ($this->thread)('5511999990001', 'Ana Souza');
    $bruno = ($this->thread)('5511999990002', 'Bruno Lima');

    $response = $this->actingAs($this->user)->postJson('/api/broadcasts/conversations', [
        'conversation_ids' => [$ana->id, $bruno->id],
        'name' => 'Aviso de feriado',
        'content_type' => 'text',
        'payload' => ['body' => 'Olá {{contact.first_name}}, voltamos amanhã'],
    ])->assertCreated()->assertJson(['queued' => 2, 'skipped' => 0]);

    $broadcast = Broadcast::findOrFail($response->json('data.0.id'));

    expect($broadcast->source)->toBe(Source::Inbox)
        ->and($broadcast->status)->toBe(Status::Completed)
        ->and($broadcast->sent_count)->toBe(2)
        ->and($broadcast->tag_id)->toBeNull()
        ->and($response->json('data.0.source'))->toBe('inbox')
        ->and(Conversation::count())->toBe(2)
        ->and(($this->said)($ana))->toBe(['Olá Ana, voltamos amanhã'])
        ->and(($this->said)($bruno))->toBe(['Olá Bruno, voltamos amanhã']);

    foreach ([$ana, $bruno] as $conversation) {
        $conversation->refresh();

        expect($conversation->status)->toBe(ConversationStatus::Active)
            ->and($conversation->user_id)->toBe($this->user->id)
            ->and($conversation->tags)->toHaveCount(0);
    }

    $message = Message::where('conversation_id', $ana->id)->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info->value)->first();

    expect($message->sent_by_user_id)->toBe($this->user->id);
});

test('resolve after sending closes each thread that received it, closing message included', function () {
    $this->line->forceFill(['closing_message' => 'Atendimento encerrado.'])->save();
    $ana = ($this->thread)('5511999990001', 'Ana Souza');

    $this->actingAs($this->user)->postJson('/api/broadcasts/conversations', [
        'conversation_ids' => [$ana->id],
        'content_type' => 'text',
        'payload' => ['body' => 'Aviso'],
        'resolve_after' => true,
    ])->assertCreated();

    $ana->refresh();

    expect($ana->status)->toBe(ConversationStatus::Resolved)
        ->and($ana->resolved_by_user_id)->toBe($this->user->id)
        // Same path as the Resolve button: the customer gets the closing message too.
        ->and(($this->said)($ana))->toBe(['Aviso', 'Atendimento encerrado.'])
        ->and(Broadcast::first()->resolve_after)->toBeTrue()
        ->and(BroadcastRecipient::first()->error)->toBeNull();
});

test('only active threads the sender is handling are included, and the rest counts as skipped', function () {
    $mine = ($this->thread)('5511999990001', 'Ana Souza');
    $queue = ($this->thread)('5511999990002', 'Bruno Lima', ['status' => ConversationStatus::Pending, 'user_id' => null]);
    $colleague = BroadcastFixtures::coworker($this->user, []);
    $theirs = ($this->thread)('5511999990003', 'Carla Dias', ['user_id' => $colleague->id]);

    $this->actingAs($this->user)->postJson('/api/broadcasts/conversations', [
        'conversation_ids' => [$mine->id, $queue->id, $theirs->id, 999999],
        'content_type' => 'text',
        'payload' => ['body' => 'Aviso'],
    ])->assertCreated()->assertJson(['queued' => 1, 'skipped' => 3]);

    expect(($this->said)($mine))->toBe(['Aviso'])
        ->and(($this->said)($queue))->toBe([])
        ->and(($this->said)($theirs))->toBe([]);
});

test('a selection with nothing eligible is refused', function () {
    $queue = ($this->thread)('5511999990002', 'Bruno Lima', ['status' => ConversationStatus::Pending, 'user_id' => null]);

    $this->actingAs($this->user)->postJson('/api/broadcasts/conversations', [
        'conversation_ids' => [$queue->id],
        'content_type' => 'text',
        'payload' => ['body' => 'Aviso'],
    ])->assertUnprocessable()->assertJsonValidationErrors('conversation_ids');

    expect(Broadcast::count())->toBe(0);
});

test('a selection across two lines becomes one delivery per line', function () {
    $this->line->forceFill(['name' => 'Suporte'])->save();
    $sales = BroadcastFixtures::connection($this->user, Channel::WhatsappOfficial);
    $sales->forceFill(['name' => 'Vendas'])->save();
    $this->user->connections()->attach($sales->id);

    $ana = ($this->thread)('5511999990001', 'Ana Souza');
    $bruno = ($this->thread)('5511999990002', 'Bruno Lima', [], $sales);

    $this->actingAs($this->user)->postJson('/api/broadcasts/conversations', [
        'conversation_ids' => [$ana->id, $bruno->id],
        'name' => 'Aviso',
        'content_type' => 'text',
        'payload' => ['body' => 'Aviso'],
    ])->assertCreated()->assertJsonCount(2, 'data')->assertJson(['queued' => 2]);

    expect(Broadcast::orderBy('name')->pluck('name')->all())->toBe(['Aviso · Suporte', 'Aviso · Vendas'])
        ->and(($this->said)($ana))->toBe(['Aviso'])
        ->and(($this->said)($bruno))->toBe(['Aviso']);
});

test('a thread closed before its turn is skipped, not written to or resolved again', function () {
    $ana = ($this->thread)('5511999990001', 'Ana Souza');
    $recipient = ($this->queued)($ana);

    $ana->markResolved();

    $status = app(BroadcastSender::class)->send($recipient->broadcast, $recipient);

    expect($status)->toBe(RecipientStatus::Skipped)
        ->and($recipient->fresh()->error)->toContain('no longer active')
        ->and(($this->said)($ana))->toBe([])
        ->and($ana->fresh()->resolved_by_user_id)->toBeNull();
});

test('a thread a colleague took over before its turn is skipped, so resolve-after cannot close their chat', function () {
    $ana = ($this->thread)('5511999990001', 'Ana Souza');
    $recipient = ($this->queued)($ana);

    $colleague = BroadcastFixtures::coworker($this->user, []);
    $ana->forceFill(['user_id' => $colleague->id])->save();

    $status = app(BroadcastSender::class)->send($recipient->broadcast, $recipient);

    expect($status)->toBe(RecipientStatus::Skipped)
        ->and($recipient->fresh()->error)->toContain('someone else')
        ->and($ana->fresh()->status)->toBe(ConversationStatus::Active)
        ->and(($this->said)($ana))->toBe([]);
});

test('a thread whose WhatsApp window has closed is skipped and left open', function () {
    $ana = ($this->thread)('5511999990001', 'Ana Souza', [], null, now()->subDays(2)->timestamp);

    $this->actingAs($this->user)->postJson('/api/broadcasts/conversations', [
        'conversation_ids' => [$ana->id],
        'content_type' => 'text',
        'payload' => ['body' => 'Aviso'],
        'resolve_after' => true,
    ])->assertCreated()->assertJson(['queued' => 1]);

    $recipient = BroadcastRecipient::first();

    expect($recipient->status)->toBe(RecipientStatus::Skipped)
        ->and($recipient->error)->toContain('window')
        ->and($ana->fresh()->status)->toBe(ConversationStatus::Active)
        ->and(($this->said)($ana))->toBe([]);
});

test('sending from the inbox needs the permission to send campaigns', function () {
    $ana = ($this->thread)('5511999990001', 'Ana Souza');
    $drafter = BroadcastFixtures::coworker($this->user, ['broadcasts.view', 'broadcasts.create']);

    $this->actingAs($drafter)->postJson('/api/broadcasts/conversations', [
        'conversation_ids' => [$ana->id],
        'content_type' => 'text',
        'payload' => ['body' => 'Aviso'],
    ])->assertForbidden();

    expect(Broadcast::count())->toBe(0);
});

test('each line sends at the pace chosen for it, and a line left out keeps its channel default', function () {
    $this->line->forceFill(['name' => 'Suporte'])->save();
    $sales = BroadcastFixtures::connection($this->user, Channel::WhatsappOfficial);
    $sales->forceFill(['name' => 'Vendas'])->save();
    $this->user->connections()->attach($sales->id);

    $ana = ($this->thread)('5511999990001', 'Ana Souza');
    $bruno = ($this->thread)('5511999990002', 'Bruno Lima', [], $sales);

    $this->actingAs($this->user)->postJson('/api/broadcasts/conversations', [
        'conversation_ids' => [$ana->id, $bruno->id],
        'name' => 'Aviso',
        'content_type' => 'text',
        'payload' => ['body' => 'Aviso'],
        'rates_per_minute' => [$this->line->id => 30],
    ])->assertCreated();

    expect((int) Broadcast::where('connection_id', $this->line->id)->value('rate_per_minute'))->toBe(30)
        ->and((int) Broadcast::where('connection_id', $sales->id)->value('rate_per_minute'))
        ->toBe(Channel::WhatsappOfficial->broadcastDefaultRatePerMinute());
});

test('a pace above the channel ceiling is refused before anything is sent', function () {
    $apiway = BroadcastFixtures::connection($this->user, Channel::WhatsappApiway);
    $this->user->connections()->attach($apiway->id);
    $ana = ($this->thread)('5511999990001', 'Ana Souza', [], $apiway);

    $this->actingAs($this->user)->postJson('/api/broadcasts/conversations', [
        'conversation_ids' => [$ana->id],
        'content_type' => 'text',
        'payload' => ['body' => 'Aviso'],
        'rates_per_minute' => [$apiway->id => Channel::WhatsappApiway->broadcastMaxRatePerMinute() + 1],
    ])->assertUnprocessable()->assertJsonValidationErrors("rates_per_minute.{$apiway->id}");

    expect(Broadcast::count())->toBe(0)
        ->and(($this->said)($ana))->toBe([]);
});
