<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\CallLog;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use App\Services\Webhook\Handlers\Chat\WhatsappCallHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

const CALL_WABA_ID = '111222333444555';
const CALL_BUSINESS_PHONE = '5511999990000';
const CALL_CUSTOMER_PHONE = '5511888887777';

function callConnection(Channel $channel = Channel::WhatsappOfficial): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => $channel === Channel::WhatsappOfficial
            ? ['phone_number_id' => '106540352242922', 'access_token' => 'test-token', 'business_account_id' => CALL_WABA_ID]
            : ['instance_id' => 'INST-1', 'token' => 'test-token'],
    ]);
}

/** One `calls` change, shaped the way Meta multiplexes it onto the WABA webhook. */
function callsChange(array $call): array
{
    return [
        'field' => 'calls',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => [
                'display_phone_number' => CALL_BUSINESS_PHONE,
                'phone_number_id' => '106540352242922',
            ],
            'contacts' => [[
                'profile' => ['name' => 'Maria'],
                'wa_id' => CALL_CUSTOMER_PHONE,
            ]],
            'calls' => [array_merge([
                'id' => 'wacid.ABGGFjFVU2AfAgo6V',
                'to' => CALL_BUSINESS_PHONE,
                'from' => CALL_CUSTOMER_PHONE,
                'direction' => 'USER_INITIATED',
            ], $call)],
        ],
    ];
}

/** whatsmeow's BasicCallMeta, wrapped the way API Way forwards every event. */
function apiwayCallEvent(string $type, array $event = []): array
{
    return [
        'type' => $type,
        'event' => array_merge([
            'From' => CALL_CUSTOMER_PHONE.'@s.whatsapp.net',
            'CallCreator' => CALL_CUSTOMER_PHONE.'@s.whatsapp.net',
            'CallID' => 'CALL-1',
            'Timestamp' => '2026-08-17T10:00:00-03:00',
        ], $event),
    ];
}

function callNote(): ?Message
{
    return Message::where('message_type', MessageType::Info)->first();
}

// ---------------------------------------------------------------- Cloud API

test('an incoming Cloud API call opens a thread and lands as a ringing note', function () {
    Event::fake();
    $connection = callConnection();

    (new WhatsappCallHandler)->handleChange($connection, callsChange([
        'event' => 'connect',
        'timestamp' => '1786621132',
        'session' => ['sdp_type' => 'offer', 'sdp' => 'v=0...'],
    ]));

    $note = callNote();

    expect($note)->not->toBeNull()
        ->and($note->body)->toBe('Incoming call.')
        ->and($note->meta['info']['code'])->toBe(CallLog::RINGING)
        ->and($note->meta['call']['direction'])->toBe('user_initiated')
        // Outgoing keeps the note out of the unread badge, which only counts
        // Incoming — the same choice every other info note makes.
        ->and($note->sender_type)->toBe(SenderType::Outgoing)
        ->and($note->external_id)->toBe('call:wacid.ABGGFjFVU2AfAgo6V');

    // A caller who never wrote still gets a thread — that is the whole point.
    $conversation = Conversation::first();
    expect($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->external_id)->toBe(CALL_CUSTOMER_PHONE)
        ->and(Contact::first()->name)->toBe('Maria');

    Event::assertDispatched(MessageReceived::class);
});

test('a completed call rewrites the same note with its length', function () {
    Event::fake();
    $connection = callConnection();
    $handler = new WhatsappCallHandler;

    $handler->handleChange($connection, callsChange(['event' => 'connect', 'timestamp' => '1786621132']));
    $handler->handleChange($connection, callsChange([
        'event' => 'terminate',
        'status' => 'COMPLETED',
        'start_time' => '1786621140',
        'end_time' => '1786621223',
        'duration' => 83,
    ]));

    // One call, one note — not one note per webhook event.
    expect(Message::count())->toBe(1);

    $note = callNote();

    expect($note->meta['info']['code'])->toBe(CallLog::ANSWERED)
        ->and($note->meta['info']['params']['seconds'])->toBe(83)
        ->and($note->body)->toBe('Call answered · 1m 23s')
        // The note stays where the call started in the timeline.
        ->and($note->sent_at)->toBe(1786621132);

    Event::assertDispatched(MessageUpdated::class);
});

test('a rejected call reads as declined and a failed one as missed', function () {
    Event::fake();

    $rejected = callConnection();
    (new WhatsappCallHandler)->handleChange($rejected, callsChange([
        'event' => 'terminate',
        'status' => 'REJECTED',
        'end_time' => '1786621223',
    ]));

    $failed = callConnection();
    (new WhatsappCallHandler)->handleChange($failed, callsChange([
        'event' => 'terminate',
        'status' => 'FAILED',
        'end_time' => '1786621223',
    ]));

    $notes = Message::where('message_type', MessageType::Info)->get();

    expect($notes->pluck('meta.info.code')->all())
        ->toBe([CallLog::DECLINED, CallLog::MISSED]);
});

test('a call that connected but never ran is missed, not answered', function () {
    Event::fake();
    $connection = callConnection();

    // COMPLETED with nothing on the clock: it rang, it connected far enough to
    // be "completed", nobody spoke.
    (new WhatsappCallHandler)->handleChange($connection, callsChange([
        'event' => 'terminate',
        'status' => 'COMPLETED',
        'duration' => 0,
    ]));

    expect(callNote()->meta['info']['code'])->toBe(CallLog::MISSED);
});

test('a terminate for a call we never saw ring still writes the note', function () {
    Event::fake();
    $connection = callConnection();

    (new WhatsappCallHandler)->handleChange($connection, callsChange([
        'event' => 'terminate',
        'status' => 'COMPLETED',
        'duration' => 12,
        'end_time' => '1786621223',
    ]));

    expect(Message::count())->toBe(1)
        ->and(callNote()->meta['info']['code'])->toBe(CallLog::ANSWERED);
});

test('a re-delivered connect never buries an outcome that is already known', function () {
    Event::fake();
    $connection = callConnection();
    $handler = new WhatsappCallHandler;

    $handler->handleChange($connection, callsChange([
        'event' => 'terminate',
        'status' => 'COMPLETED',
        'duration' => 83,
    ]));
    $handler->handleChange($connection, callsChange(['event' => 'connect', 'timestamp' => '1786621132']));

    expect(Message::count())->toBe(1)
        ->and(callNote()->meta['info']['code'])->toBe(CallLog::ANSWERED);
});

test('a call this platform placed itself is not filed as an incoming one', function () {
    Event::fake();
    $connection = callConnection();

    (new WhatsappCallHandler)->handleChange($connection, callsChange([
        'event' => 'connect',
        'direction' => 'BUSINESS_INITIATED',
    ]));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('the WABA webhook routes the calls field away from the chat handler', function () {
    Event::fake();
    $connection = callConnection();

    $this->postJson('/webhook/whatsapp', [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => CALL_WABA_ID,
            'changes' => [
                callsChange(['event' => 'connect', 'timestamp' => '1786621132']),
                [
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => CALL_BUSINESS_PHONE, 'phone_number_id' => '106540352242922'],
                        'contacts' => [['wa_id' => CALL_CUSTOMER_PHONE, 'profile' => ['name' => 'Maria']]],
                        'messages' => [[
                            'from' => CALL_CUSTOMER_PHONE,
                            'id' => 'wamid.live1',
                            'timestamp' => '1786621200',
                            'type' => 'text',
                            'text' => ['body' => 'Tentei ligar'],
                        ]],
                    ],
                ],
            ],
        ]],
    ])->assertOk();

    // Both survive the same entry: the call note and the message, in one thread.
    expect(Message::count())->toBe(2)
        ->and(Conversation::count())->toBe(1)
        ->and(callNote()->meta['info']['code'])->toBe(CallLog::RINGING);
});

// ----------------------------------------------------------------- API Way

test('an incoming API Way call lands as a ringing note', function () {
    Event::fake();
    $connection = callConnection(Channel::WhatsappApiway);

    (new WhatsappApiwayHandler)->handle($connection, apiwayCallEvent('CallOffer'));

    $note = callNote();

    expect($note)->not->toBeNull()
        ->and($note->meta['info']['code'])->toBe(CallLog::RINGING)
        ->and($note->external_id)->toBe('call:CALL-1')
        ->and(Conversation::first()->external_id)->toBe(CALL_CUSTOMER_PHONE);
});

test('an API Way call answered on the phone is timed from accept to terminate', function () {
    Event::fake();
    $connection = callConnection(Channel::WhatsappApiway);
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayCallEvent('CallOffer'));
    $handler->handle($connection, apiwayCallEvent('CallAccept', ['Timestamp' => '2026-08-17T10:00:05-03:00']));

    expect(callNote()->meta['info']['code'])->toBe(CallLog::ONGOING);

    $handler->handle($connection, apiwayCallEvent('CallTerminate', [
        'Timestamp' => '2026-08-17T10:01:28-03:00',
        'Reason' => 'accepted_elsewhere',
    ]));

    $note = callNote();

    // whatsmeow reports no duration; the accept time we kept is the only way
    // to know how long the call ran.
    expect(Message::count())->toBe(1)
        ->and($note->meta['info']['code'])->toBe(CallLog::ANSWERED)
        ->and($note->meta['info']['params']['seconds'])->toBe(83)
        ->and($note->body)->toBe('Call answered · 1m 23s');
});

test('an API Way call answered without an accept we saw is recorded without a length', function () {
    Event::fake();
    $connection = callConnection(Channel::WhatsappApiway);

    (new WhatsappApiwayHandler)->handle($connection, apiwayCallEvent('CallTerminate', [
        'Reason' => 'accepted_elsewhere',
    ]));

    $note = callNote();

    expect($note->meta['info']['code'])->toBe(CallLog::ENDED)
        ->and($note->body)->toBe('Call ended.')
        ->and($note->meta['info'])->not->toHaveKey('params');
});

test('API Way terminate reasons map to missed and declined', function () {
    Event::fake();

    $timedOut = callConnection(Channel::WhatsappApiway);
    (new WhatsappApiwayHandler)->handle($timedOut, apiwayCallEvent('CallTerminate', ['Reason' => 'timeout']));

    $declined = callConnection(Channel::WhatsappApiway);
    (new WhatsappApiwayHandler)->handle($declined, apiwayCallEvent('CallTerminate', ['Reason' => 'rejected_elsewhere']));

    expect(Message::where('message_type', MessageType::Info)->get()->pluck('meta.info.code')->all())
        ->toBe([CallLog::MISSED, CallLog::DECLINED]);
});

test('a caller who hangs up before anyone answers is a missed call', function () {
    Event::fake();
    $connection = callConnection(Channel::WhatsappApiway);
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayCallEvent('CallOffer'));
    $handler->handle($connection, apiwayCallEvent('CallReject'));

    expect(Message::count())->toBe(1)
        ->and(callNote()->meta['info']['code'])->toBe(CallLog::MISSED);
});

test('group calls and call signalling never reach a thread', function () {
    Event::fake();
    $connection = callConnection(Channel::WhatsappApiway);
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayCallEvent('CallOfferNotice', ['Type' => 'group', 'Media' => 'audio']));
    $handler->handle($connection, apiwayCallEvent('CallPreAccept'));
    $handler->handle($connection, apiwayCallEvent('CallTransport'));
    $handler->handle($connection, apiwayCallEvent('CallRelayLatency'));
    $handler->handle($connection, apiwayCallEvent('UnknownCallEvent'));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('a call from an id we cannot resolve to a number is dropped, not guessed', function () {
    Event::fake();
    $connection = callConnection(Channel::WhatsappApiway);

    // A bare @lid for someone we have never seen with a phone: keying a thread
    // off it would create one nobody can reply in.
    (new WhatsappApiwayHandler)->handle($connection, apiwayCallEvent('CallOffer', [
        'From' => '270514354389042@lid',
        'CallCreator' => '270514354389042@lid',
    ]));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('a call reuses the open thread the customer already has', function () {
    Event::fake();
    $connection = callConnection(Channel::WhatsappApiway);

    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'name' => 'Maria',
        'external_id' => CALL_CUSTOMER_PHONE,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => CALL_CUSTOMER_PHONE,
        'status' => ConversationStatus::Active,
    ]);

    (new WhatsappApiwayHandler)->handle($connection, apiwayCallEvent('CallOffer'));

    expect(Conversation::count())->toBe(1)
        ->and(callNote()->conversation_id)->toBe($conversation->id)
        // An open thread is not disturbed by a call arriving in it.
        ->and($conversation->fresh()->status)->toBe(ConversationStatus::Active);
});
