<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Media\MediaRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function retentionUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function retentionConversation(ConversationType $type = ConversationType::Private, ?Channel $channel = null): Conversation
{
    $user = retentionUser();

    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => $channel ?? Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
    ]);

    $contact = Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => $type === ConversationType::Group ? '120363419920035031@g.us' : '5511999999999',
        'name' => $type === ConversationType::Group ? 'Equipe' : 'Ana',
        'channel' => $connection->channel,
        'is_group' => $type === ConversationType::Group,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'type' => $type,
        'status' => ConversationStatus::Active,
    ]);
}

/** A media message whose file is on disk, created $daysAgo days ago. */
function retentionMessage(Conversation $conversation, int $daysAgo, array $overrides = []): Message
{
    $message = $conversation->messages()->create(array_merge([
        'external_id' => (string) Str::uuid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Image,
        'body' => 'olha isso',
        'attachment' => null,
        'sent_at' => now()->subDays($daysAgo),
    ], $overrides));

    if (($overrides['attachment'] ?? null) === null) {
        $path = 'media/'.$message->id.'_test.jpg';
        Storage::disk('local')->put($path, 'file-bytes');
        $message->attachment = $path;
    }

    $when = now()->subDays($daysAgo);
    $message->forceFill(['created_at' => $when, 'updated_at' => $when])->save();

    return $message->fresh();
}

test('group media past its 30 day window is deleted and the row marked expired', function () {
    $conversation = retentionConversation(ConversationType::Group);
    $message = retentionMessage($conversation, daysAgo: 31);
    $path = $message->attachment;

    $this->artisan('media:purge')->assertExitCode(0);

    Storage::disk('local')->assertMissing($path);

    $message->refresh();
    expect($message->attachment)->toBeNull()
        ->and($message->attachment_status)->toBe(AttachmentStatus::Expired);
});

test('group media inside the window is left alone', function () {
    $conversation = retentionConversation(ConversationType::Group);
    $message = retentionMessage($conversation, daysAgo: 29);
    $path = $message->attachment;

    $this->artisan('media:purge');

    Storage::disk('local')->assertExists($path);
    expect($message->fresh()->attachment)->toBe($path);
});

test('private media outlives the group window and is purged at 90 days', function () {
    $conversation = retentionConversation();
    $young = retentionMessage($conversation, daysAgo: 45);
    $old = retentionMessage($conversation, daysAgo: 91);

    $this->artisan('media:purge');

    Storage::disk('local')->assertExists($young->attachment);
    Storage::disk('local')->assertMissing($old->attachment);
    expect($old->fresh()->attachment)->toBeNull();
});

test('purging does not move updated_at', function () {
    // updated_at is the cursor every client's message delta sync reads. Bumping
    // it here would hand every open dashboard the whole media backlog to
    // re-download the first time this command runs.
    $conversation = retentionConversation(ConversationType::Group);
    $message = retentionMessage($conversation, daysAgo: 40);
    $before = $message->updated_at;

    $this->artisan('media:purge');

    expect($message->fresh()->updated_at->timestamp)->toBe($before->timestamp);
});

test('dry run reports what it would free without deleting', function () {
    $conversation = retentionConversation(ConversationType::Group);
    $message = retentionMessage($conversation, daysAgo: 40);

    $this->artisan('media:purge --dry-run');

    Storage::disk('local')->assertExists($message->attachment);
    expect($message->fresh()->attachment_status)->toBeNull();
});

test('media held on someone else s server is never touched', function () {
    $conversation = retentionConversation(ConversationType::Group);
    $message = retentionMessage($conversation, daysAgo: 100, overrides: [
        'attachment' => 'https://cdn.example.com/photo.jpg',
    ]);

    $this->artisan('media:purge');

    expect($message->fresh()->attachment)->toBe('https://cdn.example.com/photo.jpg');
});

test('e-mail attachments are purged from meta while the html body stays', function () {
    $conversation = retentionConversation(ConversationType::Private, Channel::Email);
    $message = retentionMessage($conversation, daysAgo: 100, overrides: [
        'message_type' => MessageType::Text,
        'attachment' => 'media/1_nota.pdf',
    ]);

    Storage::disk('local')->put('media/1_nota.pdf', 'pdf');
    Storage::disk('local')->put('media/1_contrato.pdf', 'pdf');
    Storage::disk('local')->put('media/1_body.html', '<p>oi</p>');

    $message->forceFill(['meta' => ['email' => [
        'subject' => 'Nota fiscal',
        'html_path' => 'media/1_body.html',
        'attachments' => [
            ['name' => 'nota.pdf', 'path' => 'media/1_nota.pdf'],
            ['name' => 'contrato.pdf', 'path' => 'media/1_contrato.pdf'],
        ],
    ]]])->save();

    $this->artisan('media:purge');

    Storage::disk('local')->assertMissing('media/1_nota.pdf');
    Storage::disk('local')->assertMissing('media/1_contrato.pdf');
    Storage::disk('local')->assertExists('media/1_body.html');

    $meta = $message->fresh()->meta;
    expect($meta['email']['html_path'])->toBe('media/1_body.html')
        ->and($meta['email']['attachments'][1])->toBe(['name' => 'contrato.pdf', 'expired' => true]);
});

test('widget uploads that never became a message are swept', function () {
    $conversation = retentionConversation();
    $sent = retentionMessage($conversation, daysAgo: 1, overrides: [
        'attachment' => 'widget-uploads/tok/sent.png',
    ]);

    Storage::disk('local')->put('widget-uploads/tok/sent.png', 'bytes');
    Storage::disk('local')->put('widget-uploads/tok/orphan.png', 'bytes');
    Storage::disk('local')->put('widget-uploads/tok/fresh.png', 'bytes');

    foreach (['sent.png', 'orphan.png'] as $file) {
        touch(Storage::disk('local')->path("widget-uploads/tok/{$file}"), now()->subDays(2)->getTimestamp());
    }

    $this->artisan('media:purge');

    Storage::disk('local')->assertMissing('widget-uploads/tok/orphan.png');
    // Attached to a message: it lives on that message's retention clock.
    Storage::disk('local')->assertExists('widget-uploads/tok/sent.png');
    // Not old enough to be certain nobody is about to send it.
    Storage::disk('local')->assertExists('widget-uploads/tok/fresh.png');
    expect($sent->fresh()->attachment)->toBe('widget-uploads/tok/sent.png');
});

test('the signed url expires on the day the file is deleted', function () {
    $conversation = retentionConversation(ConversationType::Group);
    $message = retentionMessage($conversation, daysAgo: 5);

    $payload = (new MessageResource($message->load('conversation.connection')))->toArray(request());
    $deadline = MediaRetention::deadlineFor($message);

    expect($deadline->timestamp)->toBe($message->created_at->copy()->addDays(30)->timestamp)
        // Storage::fake signs URLs as ?expiration=<unix>; production uses
        // ?expires=<unix> via the signed storage route. Either way the number
        // is the purge date.
        ->and($payload['attachment_url'])->toContain('expiration='.$deadline->timestamp);
});

test('a message past its window resolves to no url even before the sweep runs', function () {
    $conversation = retentionConversation(ConversationType::Group);
    $message = retentionMessage($conversation, daysAgo: 31);

    $payload = (new MessageResource($message->load('conversation.connection')))->toArray(request());

    expect($payload['attachment_url'])->toBeNull()
        ->and($payload['attachment_status'])->toBe(AttachmentStatus::Expired);
});

test('retention can be switched off entirely', function () {
    config()->set('media.retention.enabled', false);

    $conversation = retentionConversation(ConversationType::Group);
    $message = retentionMessage($conversation, daysAgo: 400);

    $this->artisan('media:purge');

    Storage::disk('local')->assertExists($message->attachment);
    expect(MediaRetention::deadlineFor($message))->toBeNull();
});
