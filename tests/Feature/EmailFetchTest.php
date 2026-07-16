<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Email\EmailInboxClient;
use App\Services\Email\EmailInboxClientFactory;
use App\Services\Email\InboundEmail;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

class FakeEmailInboxClientFactory implements EmailInboxClientFactory
{
    /**
     * @param array<int, InboundEmail> $messages
     */
    public function __construct(private readonly array $messages)
    {
    }

    public function make(Connection $connection): EmailInboxClient
    {
        return new FakeEmailInboxClient($this->messages);
    }
}

class FakeEmailInboxClient implements EmailInboxClient
{
    /**
     * @param array<int, InboundEmail> $messages
     */
    public function __construct(private readonly array $messages)
    {
    }

    public function fetchSince(int $lastSeenUid): iterable
    {
        foreach ($this->messages as $message) {
            if ($message->uid > $lastSeenUid) {
                yield $message;
            }
        }
    }

    public function disconnect(): void
    {
    }
}

function emailFetchConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Email,
        'name' => 'Suporte',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'email' => 'inbox@example.com',
            'password' => Crypt::encryptString('secret-password'),
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
        ],
    ]);
}

function inboundEmail(array $overrides = []): InboundEmail
{
    return new InboundEmail(
        uid: $overrides['uid'] ?? 1,
        messageId: $overrides['messageId'] ?? 'message-1@example.com',
        fromEmail: $overrides['fromEmail'] ?? 'customer@example.com',
        fromName: $overrides['fromName'] ?? 'Customer',
        subject: $overrides['subject'] ?? 'Support request',
        to: $overrides['to'] ?? ['inbox@example.com'],
        cc: $overrides['cc'] ?? [],
        inReplyTo: $overrides['inReplyTo'] ?? null,
        references: $overrides['references'] ?? [],
        textBody: $overrides['textBody'] ?? 'Hello support',
        htmlBody: $overrides['htmlBody'] ?? null,
        sentAt: $overrides['sentAt'] ?? Carbon::parse('2026-07-15 10:00:00'),
        attachments: $overrides['attachments'] ?? [],
    );
}

test('email fetch is idempotent when polling the same inbox twice', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    $connection = emailFetchConnection();

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory([
        inboundEmail(['uid' => 10, 'messageId' => 'unique-message@example.com']),
    ]));

    $this->artisan('email:fetch')->assertSuccessful();
    $this->artisan('email:fetch')->assertSuccessful();

    expect(Message::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and($connection->refresh()->last_seen_uid)->toBe(10);
});

test('email replies are threaded into the same conversation by headers', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    emailFetchConnection();

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory([
        inboundEmail([
            'uid' => 1,
            'messageId' => 'root-message@example.com',
            'subject' => 'Billing question',
            'textBody' => 'Initial question',
        ]),
        inboundEmail([
            'uid' => 2,
            'messageId' => 'reply-message@example.com',
            'subject' => 'Re: Billing question',
            'inReplyTo' => 'root-message@example.com',
            'references' => ['root-message@example.com'],
            'textBody' => 'Reply with more context',
        ]),
    ]));

    $this->artisan('email:fetch')->assertSuccessful();

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(2)
        ->and(Message::pluck('conversation_id')->unique()->count())->toBe(1);
});

test('email html is converted to safe plain text before storage', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    emailFetchConnection();

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory([
        inboundEmail([
            'uid' => 1,
            'messageId' => 'html-message@example.com',
            'textBody' => '',
            'htmlBody' => '<p>Hello</p><script>alert("xss")</script><b>World</b>',
        ]),
    ]));

    $this->artisan('email:fetch')->assertSuccessful();

    $body = Message::firstOrFail()->body;

    expect($body)->toContain('Hello')
        ->and($body)->toContain('World')
        ->and($body)->not->toContain('<script')
        ->and($body)->not->toContain('alert("xss")');
});
