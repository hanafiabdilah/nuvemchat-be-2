<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\ParkedInboundMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const PARKED_PHONE = '558881447724';
const PARKED_LID = '118300696653886';

beforeEach(function () {
    Event::fake();
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'OUT-'.uniqid()]])]);
});

/** An API Way connection whose flow greets: start → message. */
function parkedConnection(bool $withFlow = true): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flowId = null;

    if ($withFlow) {
        $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Greeter']);
        $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
        $hello = $flow->nodes()->create([
            'type' => NodeType::Message,
            'data' => ['body' => 'Olá! Como posso ajudar?', 'message_type' => 'text'],
            'position_x' => 200,
            'position_y' => 0,
        ]);
        FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $hello->id, 'condition_value' => null]);
        $flowId = $flow->id;
    }

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flowId,
        'credentials' => ['instance_id' => 'INST-1', 'token' => 'test-token'],
    ]);
}

/** A customer message. `$withPhone = false` is the stripped re-delivery. */
function parkedIncoming(string $id, string $body, string $at, bool $withPhone = true): array
{
    return [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => $id,
                'Chat' => PARKED_LID.'@lid',
                'Sender' => PARKED_LID.'@lid',
                'SenderAlt' => $withPhone ? PARKED_PHONE.'@s.whatsapp.net' : null,
                'PushName' => $withPhone ? 'Lead' : null,
                'IsFromMe' => false,
                'IsGroup' => false,
                'Timestamp' => $at,
                'Type' => $withPhone ? 'text' : null,
            ],
            'Message' => ['conversation' => $body],
            'UnavailableRequestID' => $withPhone ? null : '3EB0FD0C2D607BAF136264',
        ],
    ];
}

/** Something typed on the connected phone itself. */
function parkedPhoneEcho(string $id, string $body, string $at): array
{
    return [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => $id,
                'Chat' => PARKED_LID.'@lid',
                'Sender' => '113366416769264@lid',
                'SenderAlt' => null,
                'RecipientAlt' => PARKED_PHONE.'@s.whatsapp.net',
                'IsFromMe' => true,
                'IsGroup' => false,
                'Timestamp' => $at,
                'Type' => 'text',
            ],
            'Message' => ['conversation' => $body],
            'UnavailableRequestID' => null,
        ],
    ];
}

test('a first message that arrives with only a lid is held, not lost', function () {
    $connection = parkedConnection();

    (new WhatsappApiwayHandler)->handle($connection, parkedIncoming('AC01', 'Olá! quero saber mais sobre!', '2026-10-01T11:08:00-03:00', withPhone: false));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0)
        ->and(ParkedInboundMessage::whereNull('replayed_at')->count())->toBe(1);
});

test('the held message is delivered, and the flow starts once, when the customer writes again', function () {
    $connection = parkedConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, parkedIncoming('AC01', 'Olá! quero saber mais sobre!', '2026-10-01T11:08:00-03:00', withPhone: false));
    $handler->handle($connection, parkedIncoming('AC02', 'oi?', '2026-10-01T11:09:00-03:00'));

    expect(Conversation::count())->toBe(1)
        ->and(Message::where('sender_type', SenderType::Incoming)->pluck('external_id')->sort()->values()->all())->toBe(['AC01', 'AC02'])
        ->and(FlowState::count())->toBe(1)
        ->and(Message::where('sender_type', SenderType::Outgoing)->count())->toBe(1)
        ->and(ParkedInboundMessage::whereNull('replayed_at')->count())->toBe(0);
});

test('the flow greets a lead whose message only surfaces after the phone answered it', function () {
    $connection = parkedConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, parkedIncoming('AC01', 'Olá! quero saber mais sobre!', '2026-10-01T11:08:00-03:00', withPhone: false));
    $handler->handle($connection, parkedPhoneEcho('A57B', 'Ola', '2026-10-01T11:09:12-03:00'));

    expect(Conversation::count())->toBe(1)
        ->and(Message::where('external_id', 'AC01')->exists())->toBeTrue()
        ->and(FlowState::count())->toBe(1);
});

test('a re-delivery that lands just after the phone\'s reply still opens the flow', function () {
    $connection = parkedConnection();
    $handler = new WhatsappApiwayHandler;

    // The lid is already known, so the late copy resolves on its own.
    $handler->handle($connection, parkedPhoneEcho('A57B', 'Oi, já te respondo', '2026-10-01T11:08:05-03:00'));
    $handler->handle($connection, parkedIncoming('AC01', 'Olá! quero saber mais sobre!', '2026-10-01T11:08:00-03:00', withPhone: false));

    expect(Conversation::count())->toBe(1)
        ->and(FlowState::count())->toBe(1);
});

test('a thread the business started does not get the flow when the customer replies', function () {
    $connection = parkedConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, parkedPhoneEcho('A57B', 'Boa noite Rafael', '2026-10-01T20:48:30-03:00'));
    $handler->handle($connection, parkedIncoming('AC01', 'Boa noite', '2026-10-01T20:48:40-03:00'));

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(2)
        ->and(FlowState::count())->toBe(0);
});

test('a held message is not replayed once it is a day old', function () {
    $connection = parkedConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, parkedIncoming('AC01', 'Olá!', '2026-10-01T11:08:00-03:00', withPhone: false));
    ParkedInboundMessage::query()->update(['created_at' => now()->subHours(ParkedInboundMessage::REPLAY_WINDOW_HOURS + 1)]);

    $handler->handle($connection, parkedIncoming('AC02', 'oi?', '2026-10-03T11:09:00-03:00'));

    expect(Message::where('external_id', 'AC01')->exists())->toBeFalse()
        ->and(ParkedInboundMessage::whereNull('replayed_at')->count())->toBe(1);
});
