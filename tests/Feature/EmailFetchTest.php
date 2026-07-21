<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Connection\SyncStatus;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Jobs\SyncEmailInbox;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Email\EmailInboxClient;
use App\Services\Email\EmailInboxClientFactory;
use App\Services\Email\EmailInboxSynchronizer;
use App\Services\Email\InboundEmail;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

class FakeEmailInboxClientFactory implements EmailInboxClientFactory
{
    /**
     * @param  array<int, InboundEmail>  $messages
     */
    public function __construct(
        private readonly array $messages,
        private readonly ?\Throwable $failWith = null,
    ) {}

    public function make(Connection $connection): EmailInboxClient
    {
        if ($this->failWith) {
            throw $this->failWith;
        }

        return new FakeEmailInboxClient($this->messages);
    }
}

class FakeEmailInboxClient implements EmailInboxClient
{
    /**
     * @param  array<int, InboundEmail>  $messages
     */
    public function __construct(private readonly array $messages) {}

    public function fetchSince(int $lastSeenUid, int $limit): iterable
    {
        $yielded = 0;

        foreach ($this->pending($lastSeenUid) as $message) {
            if ($yielded >= $limit) {
                return;
            }

            $yielded++;
            yield $message;
        }
    }

    public function countSince(int $lastSeenUid): int
    {
        return count($this->pending($lastSeenUid));
    }

    /**
     * @return array<int, InboundEmail>
     */
    private function pending(int $lastSeenUid): array
    {
        $pending = array_values(array_filter(
            $this->messages,
            fn (InboundEmail $message) => $message->uid > $lastSeenUid
        ));

        usort($pending, fn ($a, $b) => $a->uid <=> $b->uid);

        return $pending;
    }

    public function disconnect(): void {}
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

    $this->artisan('email:fetch --sync')->assertSuccessful();
    $this->artisan('email:fetch --sync')->assertSuccessful();

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

    $this->artisan('email:fetch --sync')->assertSuccessful();

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

    $this->artisan('email:fetch --sync')->assertSuccessful();

    $body = Message::firstOrFail()->body;

    expect($body)->toContain('Hello')
        ->and($body)->toContain('World')
        ->and($body)->not->toContain('<script')
        ->and($body)->not->toContain('alert("xss")');
});

test('a completed pass reports idle with no backlog left', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    $connection = emailFetchConnection();

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory([
        inboundEmail(['uid' => 4, 'messageId' => 'a@example.com']),
        inboundEmail(['uid' => 5, 'messageId' => 'b@example.com']),
    ]));

    $this->artisan('email:fetch --sync')->assertSuccessful();

    $connection->refresh();

    expect($connection->sync_status)->toBe(SyncStatus::Idle)
        ->and($connection->sync_remaining)->toBe(0)
        ->and($connection->sync_error)->toBeNull()
        ->and($connection->sync_started_at)->toBeNull()
        ->and($connection->last_synced_at)->not->toBeNull();
});

test('a large mailbox is imported in bounded batches and reports the backlog', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    $connection = emailFetchConnection();

    // One more than a single pass can carry.
    $total = EmailInboxSynchronizer::BATCH_SIZE + 5;
    $messages = [];
    for ($uid = 1; $uid <= $total; $uid++) {
        $messages[] = inboundEmail(['uid' => $uid, 'messageId' => "bulk-{$uid}@example.com"]);
    }

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory($messages));

    // First pass: capped at BATCH_SIZE, with the rest reported as remaining.
    $this->artisan('email:fetch --sync')->assertSuccessful();

    expect(Message::count())->toBe(EmailInboxSynchronizer::BATCH_SIZE)
        ->and($connection->refresh()->sync_remaining)->toBe(5)
        ->and($connection->sync_status)->toBe(SyncStatus::Idle);

    // Second pass drains the rest — the backfill resumes rather than restarting.
    $this->artisan('email:fetch --sync')->assertSuccessful();

    expect(Message::count())->toBe($total)
        ->and($connection->refresh()->sync_remaining)->toBe(0)
        ->and($connection->last_seen_uid)->toBe($total);
});

test('a failing mailbox is recorded as failed with the reason', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    $connection = emailFetchConnection();

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory(
        [],
        new RuntimeException('IMAP authentication failed')
    ));

    // One unreachable mailbox is isolated: the run completes so the other
    // connections still get synced.
    $this->artisan('email:fetch --sync')->assertSuccessful();

    $connection->refresh();

    expect($connection->sync_status)->toBe(SyncStatus::Failed)
        ->and($connection->sync_error)->toContain('IMAP authentication failed')
        ->and($connection->sync_started_at)->toBeNull();
});

test('the scheduled command queues one unique job per active mailbox', function () {
    Queue::fake();
    $connection = emailFetchConnection();

    $this->artisan('email:fetch')->assertSuccessful();

    Queue::assertPushed(SyncEmailInbox::class, fn ($job) => $job->connectionId === $connection->id);
});

test('an in-flight sync is not queued again', function () {
    Queue::fake();
    $connection = emailFetchConnection();
    $connection->forceFill([
        'sync_status' => SyncStatus::Syncing,
        'sync_started_at' => now(),
    ])->save();

    $this->artisan('email:fetch')->assertSuccessful();

    Queue::assertNotPushed(SyncEmailInbox::class);
});

test('a sync stuck past the stale window is picked back up', function () {
    Queue::fake();
    $connection = emailFetchConnection();
    $connection->forceFill([
        'sync_status' => SyncStatus::Syncing,
        // Worker died here; without staleness handling this mailbox would never sync again.
        'sync_started_at' => now()->subMinutes(EmailInboxSynchronizer::STALE_AFTER_MINUTES + 1),
    ])->save();

    $this->artisan('email:fetch')->assertSuccessful();

    Queue::assertPushed(SyncEmailInbox::class);
});
