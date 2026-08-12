<?php

use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status;
use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\BroadcastFixtures;

uses(RefreshDatabase::class);

/**
 * The queue runs synchronously under test, so starting a campaign drives it to
 * completion inside the request — which is exactly the end-to-end behaviour
 * these tests want to assert.
 */
function runCampaign(array $body, $user): Broadcast
{
    $id = test()->actingAs($user)
        ->postJson('/api/broadcasts', $body + ['start_now' => true])
        ->assertCreated()
        ->json('data.id');

    return Broadcast::findOrFail($id);
}

test('every recipient ends up with a contact, a conversation and a message', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana Souza');

    $broadcast = runCampaign(BroadcastFixtures::templateCampaign($connection, [
        'contact_ids' => [$ana->id],
        'manual_recipients' => [['address' => '5511999990002', 'name' => 'Bruno Lima']],
    ]), $user);

    expect($broadcast->status)->toBe(Status::Completed)
        ->and($broadcast->sent_count)->toBe(2)
        ->and($broadcast->failed_count)->toBe(0)
        ->and($broadcast->finished_at)->not->toBeNull();

    // The pasted number became a real contact.
    $bruno = Contact::where('external_id', '5511999990002')->first();
    expect($bruno)->not->toBeNull()->and($bruno->name)->toBe('Bruno Lima');

    foreach (BroadcastRecipient::where('broadcast_id', $broadcast->id)->get() as $recipient) {
        expect($recipient->status)->toBe(RecipientStatus::Sent)
            ->and($recipient->conversation_id)->not->toBeNull()
            ->and($recipient->message_id)->not->toBeNull()
            ->and($recipient->sent_at)->not->toBeNull();

        $conversation = Conversation::find($recipient->conversation_id);

        expect($conversation->status)->toBe(ConversationStatus::Active)
            // Assigned to whoever fired the campaign, so a blast does not fill
            // the shared unassigned queue with a thousand threads.
            ->and($conversation->user_id)->toBe($user->id)
            ->and($conversation->tags->pluck('name')->all())->toBe(['Promo de agosto']);

        $message = Message::find($recipient->message_id);

        expect($message->sender_type)->toBe(SenderType::Outgoing)
            ->and($message->message_type)->toBe(MessageType::Template)
            ->and($message->sent_by_user_id)->toBe($user->id);
    }
});

test('each delivery announces its conversation, which is what makes threads appear one by one', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);
    Event::fake([ConversationUpdated::class, MessageReceived::class]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);

    runCampaign(BroadcastFixtures::templateCampaign($connection, [
        'manual_recipients' => [
            ['address' => '5511999990001'],
            ['address' => '5511999990002'],
            ['address' => '5511999990003'],
        ],
    ]), $user);

    Event::assertDispatchedTimes(ConversationUpdated::class, 3);
    Event::assertDispatchedTimes(MessageReceived::class, 3);
});

test('per-recipient variables are resolved from the recipient, not the campaign', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);

    runCampaign(BroadcastFixtures::templateCampaign($connection, [
        'manual_recipients' => [
            ['address' => '5511999990001', 'name' => 'Ana Souza'],
            ['address' => '5511999990002', 'name' => 'Bruno Lima'],
        ],
    ]), $user);

    $greetings = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0]->data()['template']['components'][0]['parameters'][0]['text'])
        ->sort()
        ->values()
        ->all();

    expect($greetings)->toBe(['Ana', 'Bruno']);
});

test('an existing open thread is continued instead of forked', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana');
    $existing = BroadcastFixtures::conversationWithInbound($connection, $ana);

    $broadcast = runCampaign(BroadcastFixtures::templateCampaign($connection, [
        'contact_ids' => [$ana->id],
    ]), $user);

    expect(Conversation::where('contact_id', $ana->id)->count())->toBe(1);
    expect(BroadcastRecipient::where('broadcast_id', $broadcast->id)->first()->conversation_id)->toBe($existing->id);
});

test('one refused recipient is recorded and the rest still go out', function () {
    $calls = 0;

    Http::fake(['graph.facebook.com/*' => function () use (&$calls) {
        $calls++;

        return $calls === 2
            ? Http::response(['error' => ['message' => 'Recipient phone number not in allowed list']], 400)
            : Http::response(['messages' => [['id' => 'wamid.sent']]]);
    }]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);

    $broadcast = runCampaign(BroadcastFixtures::templateCampaign($connection, [
        'manual_recipients' => [
            ['address' => '5511999990001'],
            ['address' => '5511999990002'],
            ['address' => '5511999990003'],
        ],
    ]), $user);

    expect($broadcast->status)->toBe(Status::Completed)
        ->and($broadcast->sent_count)->toBe(2)
        ->and($broadcast->failed_count)->toBe(1);

    $failed = BroadcastRecipient::where('broadcast_id', $broadcast->id)
        ->where('status', RecipientStatus::Failed)
        ->first();

    // Meta's own words, not a paraphrase — an operator has to be able to look
    // the message up.
    expect($failed->error)->toContain('not in allowed list')
        ->and($failed->conversation_id)->toBeNull();

    // And no empty thread left behind for the send that never landed.
    expect(Conversation::where('external_id', $failed->address)->count())->toBe(0);
});

test('a free-form campaign runs on a channel that has no session window', function () {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'apiway-1']])]);

    $user = BroadcastFixtures::user();
    $apiway = BroadcastFixtures::connection($user, Channel::WhatsappApiway);
    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana Souza', Channel::WhatsappApiway);

    $broadcast = runCampaign([
        'name' => 'Aviso API Way',
        'connection_id' => $apiway->id,
        'content_type' => 'text',
        'payload' => ['body' => 'Olá {{contact.first_name}}!'],
        'contact_ids' => [$ana->id],
    ], $user);

    expect($broadcast->sent_count)->toBe(1);
    // The token was resolved per recipient, not stored resolved.
    expect(Message::latest('id')->first()->body)->toBe('Olá Ana!');
    expect($broadcast->payload['body'])->toBe('Olá {{contact.first_name}}!');
});

test('a contact with no thread on an internal-id channel is skipped, not failed', function () {
    Http::fake(['*' => Http::response(['result' => ['message_id' => 7]])]);

    $user = BroadcastFixtures::user();
    $telegram = BroadcastFixtures::connection($user, Channel::Telegram);
    // Never wrote to us, so there is no chat id to send to.
    $ana = BroadcastFixtures::contact($user, '55001', 'Ana', Channel::Telegram);

    $broadcast = runCampaign([
        'name' => 'Aviso Telegram',
        'connection_id' => $telegram->id,
        'content_type' => 'text',
        'payload' => ['body' => 'Olá!'],
        'contact_ids' => [$ana->id],
    ], $user);

    $recipient = BroadcastRecipient::where('broadcast_id', $broadcast->id)->first();

    expect($recipient->status)->toBe(RecipientStatus::Skipped)
        ->and($recipient->error)->toContain('has to message first')
        ->and($broadcast->skipped_count)->toBe(1)
        ->and($broadcast->failed_count)->toBe(0);
});
