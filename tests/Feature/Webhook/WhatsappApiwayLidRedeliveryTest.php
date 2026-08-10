<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

const LID_PHONE = '6282122787699';
const LID_HANDLE = '204457707106524';
const LID_MSG_ID = '3BA1E696FDEB95200A81';

function lidConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-1', 'token' => 'test-token'],
    ]);
}

/** The good copy: LID addressing, but the real phone is in SenderAlt. */
function lidGoodCopy(string $messageId = LID_MSG_ID, string $body = 'ppp'): array
{
    return [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => $messageId,
                'Chat' => LID_HANDLE.'@lid',
                'Sender' => LID_HANDLE.':73@lid',
                'SenderAlt' => LID_PHONE.':73@s.whatsapp.net',
                'PushName' => 'Hanafi Abdilah',
                'IsFromMe' => false,
                'IsGroup' => false,
                'Timestamp' => '2026-08-10T00:52:43-03:00',
                'Type' => 'text',
            ],
            'Message' => ['conversation' => $body],
            'RetryCount' => 0,
            'UnavailableRequestID' => null,
        ],
    ];
}

/**
 * The re-delivery of a message whatsmeow first failed to decrypt: Info is
 * stripped to the @lid — no SenderAlt, no PushName, no Type — and
 * UnavailableRequestID is set. Verbatim shape of prod message 55545.
 */
function lidRetryCopy(string $messageId = LID_MSG_ID, string $body = 'ppp'): array
{
    return [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => $messageId,
                'Chat' => LID_HANDLE.'@lid',
                'Sender' => LID_HANDLE.'@lid',
                'SenderAlt' => null,
                'PushName' => null,
                'IsFromMe' => false,
                'IsGroup' => false,
                'Timestamp' => '2026-08-10T00:52:42-03:00',
                'Type' => null,
            ],
            'Message' => ['conversation' => $body],
            'RetryCount' => 0,
            'UnavailableRequestID' => '3EB06207542018717303D5',
        ],
    ];
}

test('a re-delivered message does not fork the customer into a second lid-keyed conversation', function () {
    Event::fake();
    $connection = lidConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, lidGoodCopy());
    $handler->handle($connection, lidRetryCopy());

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(1)
        ->and(Conversation::first()->external_id)->toBe(LID_PHONE)
        ->and(Contact::where('external_id', LID_HANDLE)->exists())->toBeFalse();
});

test('the lid alias is recorded so a later lid-only event resolves to the same contact', function () {
    Event::fake();
    $connection = lidConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, lidGoodCopy());

    expect(Contact::where('external_id', LID_PHONE)->value('lid'))->toBe(LID_HANDLE.'@lid');

    // A different message arriving lid-only still lands in the existing thread.
    $handler->handle($connection, lidRetryCopy('MSG-LID-ONLY', 'segunda'));

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(2)
        ->and(Contact::count())->toBe(1)
        ->and(Message::orderBy('id')->pluck('body')->all())->toBe(['ppp', 'segunda']);
});

test('a lid-only event for a stranger is dropped rather than creating an unrepliable ghost thread', function () {
    Event::fake();
    $connection = lidConnection();

    (new WhatsappApiwayHandler)->handle($connection, lidRetryCopy('MSG-UNKNOWN', 'quem sou eu'));

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(Contact::count())->toBe(0);
});

test('the lid alias is scoped to the tenant', function () {
    Event::fake();
    $handler = new WhatsappApiwayHandler;
    $mine = lidConnection();
    $theirs = lidConnection();

    $handler->handle($mine, lidGoodCopy());
    // Same @lid, different tenant — must not borrow the other tenant's contact.
    $handler->handle($theirs, lidRetryCopy('MSG-OTHER-TENANT', 'oi'));

    expect(Conversation::where('connection_id', $theirs->id)->count())->toBe(0)
        ->and(Message::count())->toBe(1);
});
