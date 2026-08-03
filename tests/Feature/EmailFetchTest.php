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
use App\Services\Connection\Channels\EmailChannel;
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

    public function uidsAfter(int $uid): array
    {
        return $this->uids(fn (InboundEmail $message) => $message->uid > $uid);
    }

    public function uidsWithin(?DateTimeInterface $since, ?int $beforeUid): array
    {
        return $this->uids(fn (InboundEmail $message) => ($beforeUid === null || $message->uid < $beforeUid)
            && ($since === null || ($message->sentAt !== null && $message->sentAt->gte($since))));
    }

    public function fetch(array $uids): iterable
    {
        $byUid = [];

        foreach ($this->messages as $message) {
            $byUid[$message->uid] = $message;
        }

        foreach ($uids as $uid) {
            if (isset($byUid[$uid])) {
                yield $byUid[$uid];
            }
        }
    }

    /**
     * @param  \Closure(InboundEmail): bool  $matches
     * @return array<int, int>
     */
    private function uids(\Closure $matches): array
    {
        $uids = array_map(
            fn (InboundEmail $message) => $message->uid,
            array_values(array_filter($this->messages, $matches))
        );

        sort($uids);

        return $uids;
    }

    public function disconnect(): void {}
}

/** EmailChannel without the live IMAP/SMTP probes, to test the save logic. */
class FakeEmailChannel extends EmailChannel
{
    protected function assertImapLogin(array $credentials, string $password): void {}

    protected function assertSmtpLogin(array $credentials, string $password): void {}
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

test('legacy single-byte bodies are converted to utf-8 instead of failing', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    emailFetchConnection();

    // ISO-8859-1 bytes, the exact shape of the production poison email:
    // MySQL rejects \xE1 with error 1366 and the mailbox never advances.
    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory([
        inboundEmail([
            'uid' => 1,
            'messageId' => 'latin1-message@example.com',
            'subject' => "Lembrete de vencimento em Maring\xE1",
            'fromName' => "Jo\xE3o",
            'textBody' => "Maring\xE1-PR, 08 de novembro de 2025.",
        ]),
    ]));

    $this->artisan('email:fetch --sync')->assertSuccessful();

    $message = Message::firstOrFail();

    expect($message->body)->toBe('Maringá-PR, 08 de novembro de 2025.')
        ->and($message->meta['email']['subject'])->toBe('Lembrete de vencimento em Maringá')
        ->and(mb_check_encoding($message->body, 'UTF-8'))->toBeTrue();
});

test('oversized bodies are truncated to fit the column', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    emailFetchConnection();

    // Multibyte filler so the cut lands mid-character without the byte guard.
    $body = str_repeat('á', EmailInboxSynchronizer::MAX_BODY_BYTES);

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory([
        inboundEmail([
            'uid' => 1,
            'messageId' => 'oversized-message@example.com',
            'textBody' => $body,
        ]),
    ]));

    $this->artisan('email:fetch --sync')->assertSuccessful();

    $stored = Message::firstOrFail()->body;

    expect(strlen($stored))->toBeLessThanOrEqual(EmailInboxSynchronizer::MAX_BODY_BYTES)
        ->and(mb_check_encoding($stored, 'UTF-8'))->toBeTrue();
});

test('a message that cannot be persisted is skipped and the cursor still advances', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    $connection = emailFetchConnection();

    // Stand-in for a DB-level rejection sqlite cannot reproduce (strict
    // MySQL charset/length errors): fail exactly one message at persist time.
    Message::creating(function (Message $message) {
        if ($message->external_id === 'poison@example.com') {
            throw new RuntimeException('simulated insert failure');
        }
    });

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory([
        inboundEmail(['uid' => 1, 'messageId' => 'ok-1@example.com']),
        inboundEmail(['uid' => 2, 'messageId' => 'poison@example.com']),
        inboundEmail(['uid' => 3, 'messageId' => 'ok-2@example.com']),
    ]));

    $this->artisan('email:fetch --sync')->assertSuccessful();

    $connection->refresh();

    expect(Message::count())->toBe(2)
        ->and($connection->last_seen_uid)->toBe(3)
        ->and($connection->sync_status)->toBe(SyncStatus::Idle)
        ->and($connection->sync_remaining)->toBe(0);
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
    // The backfill walks newest-first, so what waits is the OLDEST mail — the
    // inbox shows current conversations from the very first pass.
    $this->artisan('email:fetch --sync')->assertSuccessful();

    expect(Message::count())->toBe(EmailInboxSynchronizer::BATCH_SIZE)
        ->and(Message::where('external_id', "bulk-{$total}@example.com")->exists())->toBeTrue()
        ->and(Message::where('external_id', 'bulk-1@example.com')->exists())->toBeFalse()
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

test('a sync window imports only mail inside it', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    $connection = emailFetchConnection();
    $connection->forceFill(['sync_window_days' => 30])->save();

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory([
        inboundEmail(['uid' => 1, 'messageId' => 'ancient@example.com', 'sentAt' => now()->subDays(400)]),
        inboundEmail(['uid' => 2, 'messageId' => 'old@example.com', 'sentAt' => now()->subDays(45)]),
        inboundEmail(['uid' => 3, 'messageId' => 'recent@example.com', 'sentAt' => now()->subDays(3)]),
        inboundEmail(['uid' => 4, 'messageId' => 'today@example.com', 'sentAt' => now()]),
    ]));

    $this->artisan('email:fetch --sync')->assertSuccessful();

    $connection->refresh();

    expect(Message::count())->toBe(2)
        ->and(Message::where('external_id', 'old@example.com')->exists())->toBeFalse()
        ->and(Message::where('external_id', 'today@example.com')->exists())->toBeTrue()
        // The tail cursor sits at the top of the mailbox, so out-of-window
        // history is never mistaken for new mail on later passes.
        ->and($connection->last_seen_uid)->toBe(4)
        ->and($connection->backfill_done)->toBeTrue()
        ->and($connection->sync_remaining)->toBe(0);
});

test('widening the sync window backfills older mail without refetching newer mail', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    $connection = emailFetchConnection();
    $connection->forceFill(['sync_window_days' => 30])->save();

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory([
        inboundEmail(['uid' => 1, 'messageId' => 'older@example.com', 'sentAt' => now()->subDays(60)]),
        inboundEmail(['uid' => 2, 'messageId' => 'newer@example.com', 'sentAt' => now()->subDays(5)]),
    ]));

    $this->artisan('email:fetch --sync')->assertSuccessful();

    expect(Message::count())->toBe(1)
        ->and($connection->refresh()->backfill_done)->toBeTrue();

    // What EmailChannel does when the user picks a wider window: re-arm the
    // backfill, keeping the UID floor so newer mail is not walked again.
    $connection->forceFill(['sync_window_days' => 90, 'backfill_done' => false])->save();

    $this->artisan('email:fetch --sync')->assertSuccessful();

    $connection->refresh();

    expect(Message::count())->toBe(2)
        ->and(Message::where('external_id', 'older@example.com')->exists())->toBeTrue()
        ->and($connection->backfill_done)->toBeTrue()
        ->and($connection->sync_remaining)->toBe(0);
});

test('a legacy mid-sync backlog folds into the windowed newest-first backfill', function () {
    Event::fake([MessageReceived::class, ConversationUpdated::class]);
    $connection = emailFetchConnection();

    // State left by the old oldest-first walk + the migration: the cursor sits
    // low in the mailbox and the connection is marked backfill_done, so the
    // whole backlog above the cursor looks like "new mail" to the tail phase —
    // which would drain it oldest-first, ignoring the 30-day window.
    $connection->forceFill([
        'last_seen_uid' => 5,
        'sync_window_days' => 30,
        'backfill_done' => true,
    ])->save();

    // Backlog above the cursor bigger than one pass: uids 6..210 (205 > 200),
    // of which only uids 150..210 are inside the 30-day window.
    $messages = [];
    for ($uid = 6; $uid <= 210; $uid++) {
        $messages[] = inboundEmail([
            'uid' => $uid,
            'messageId' => "legacy-{$uid}@example.com",
            'sentAt' => $uid >= 150 ? now()->subDays(5) : now()->subDays(100),
        ]);
    }

    app()->instance(EmailInboxClientFactory::class, new FakeEmailInboxClientFactory($messages));

    $this->artisan('email:fetch --sync')->assertSuccessful();

    $connection->refresh();

    // Only the in-window slice was imported (newest-first), the ceiling sits
    // at the mailbox top, and the reported total reflects the window — not
    // the old full backlog.
    expect(Message::count())->toBe(61)
        ->and(Message::where('external_id', 'legacy-100@example.com')->exists())->toBeFalse()
        ->and(Message::where('external_id', 'legacy-210@example.com')->exists())->toBeTrue()
        ->and($connection->last_seen_uid)->toBe(210)
        ->and($connection->backfill_done)->toBeTrue()
        ->and($connection->sync_remaining)->toBe(0);
});

test('only widening the sync window re-arms a finished backfill', function () {
    $connection = emailFetchConnection();
    $connection->forceFill([
        'sync_window_days' => 90,
        'last_seen_uid' => 10,
        'backfill_uid' => 1,
        'backfill_done' => true,
    ])->save();

    $payload = fn (?int $days) => [
        'email' => 'inbox@example.com',
        'imap_host' => 'imap.example.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl',
        'sync_window_days' => $days,
    ];

    $channel = new FakeEmailChannel;

    // Narrowing (90 → 30): everything inside the smaller window is already
    // local, so the backfill must stay finished — re-arming would re-walk
    // (and re-broadcast) the whole 30-day stretch for nothing.
    $channel->updateCredentials($connection, $payload(30));

    expect($connection->refresh()->sync_window_days)->toBe(30)
        ->and($connection->backfill_done)->toBeTrue();

    // Widening (30 → 365): older mail is now inside the window — re-arm, but
    // keep the UID floor so newer mail is never walked again.
    $channel->updateCredentials($connection, $payload(365));

    expect($connection->refresh()->sync_window_days)->toBe(365)
        ->and($connection->backfill_done)->toBeFalse()
        ->and($connection->backfill_uid)->toBe(1);
});

test('the scheduled command queues one unique job per active mailbox', function () {
    Queue::fake();
    $connection = emailFetchConnection();

    $this->artisan('email:fetch')->assertSuccessful();

    // The dedicated queue matters: a slow IMAP pass on `default` blocks every
    // realtime broadcast behind it (production runs a separate email worker).
    Queue::assertPushedOn('email', SyncEmailInbox::class, fn ($job) => $job->connectionId === $connection->id);
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
