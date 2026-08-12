<?php

use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Broadcast\OptOutDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BroadcastFixtures;

uses(RefreshDatabase::class);

function guardedCampaign(array $body, $user): Broadcast
{
    $id = test()->actingAs($user)
        ->postJson('/api/broadcasts', $body + ['start_now' => true])
        ->assertCreated()
        ->json('data.id');

    return Broadcast::findOrFail($id);
}

test('a contact who asked to stop is never included in a campaign', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);

    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana');
    $bruno = BroadcastFixtures::contact($user, '5511999990002', 'Bruno');
    $ana->update(['broadcast_opted_out_at' => now()]);

    $response = $this->actingAs($user)->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($connection, [
        'contact_ids' => [$ana->id, $bruno->id],
    ]));

    // Filtered out of the selection rather than failing the whole campaign: the
    // dashboard lets you select everything a filter matched.
    $response->assertCreated()->assertJsonPath('data.total', 1);

    expect(BroadcastRecipient::where('broadcast_id', $response->json('data.id'))->first()->address)
        ->toBe('5511999990002');
});

test('an opt-out that arrives after the list was built still stops the send', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana');

    $id = $this->actingAs($user)
        ->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($connection, ['contact_ids' => [$ana->id]]))
        ->json('data.id');

    // Ana replies PARAR between the campaign being drafted and being fired.
    $ana->update(['broadcast_opted_out_at' => now()]);

    $this->actingAs($user)->postJson("/api/broadcasts/{$id}/start")->assertOk();

    $recipient = BroadcastRecipient::where('broadcast_id', $id)->first();

    expect($recipient->status)->toBe(RecipientStatus::Skipped)
        ->and($recipient->error)->toContain('opted out');

    Http::assertNothingSent();
});

test('free-form content is skipped once the session window has shut', function () {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'x']])]);

    $user = BroadcastFixtures::user();
    // TikTok has a 48h window and no template to fall back on.
    $tiktok = BroadcastFixtures::connection($user, Channel::TikTok);
    $ana = BroadcastFixtures::contact($user, 'tt-ana', 'Ana', Channel::TikTok);

    // Last wrote to us three days ago.
    BroadcastFixtures::conversationWithInbound($tiktok, $ana, now()->subDays(3)->timestamp);

    $broadcast = guardedCampaign([
        'name' => 'Aviso TikTok',
        'connection_id' => $tiktok->id,
        'content_type' => 'text',
        'payload' => ['body' => 'Olá!'],
        'contact_ids' => [$ana->id],
    ], $user);

    $recipient = BroadcastRecipient::where('broadcast_id', $broadcast->id)->first();

    expect($recipient->status)->toBe(RecipientStatus::Skipped)
        ->and($recipient->error)->toContain('window')
        ->and($broadcast->skipped_count)->toBe(1);

    Http::assertNothingSent();
});

test('a template reaches someone whose window has long since shut', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.sent']]])]);

    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana');
    BroadcastFixtures::conversationWithInbound($connection, $ana, now()->subDays(30)->timestamp);

    $broadcast = guardedCampaign(BroadcastFixtures::templateCampaign($connection, [
        'contact_ids' => [$ana->id],
    ]), $user);

    expect($broadcast->sent_count)->toBe(1);
});

test('a group is never a campaign recipient', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);

    $group = BroadcastFixtures::contact($user, '12345@g.us', 'Equipe');
    $group->update(['is_group' => true]);

    $this->actingAs($user)
        ->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($connection, [
            'contact_ids' => [$group->id],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('recipients');
});

test('replying with a stop word records the opt-out', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana');

    $conversation = Conversation::create([
        'contact_id' => $ana->id,
        'connection_id' => $connection->id,
        'external_id' => $ana->external_id,
        'status' => ConversationStatus::Active,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'PARAR',
        'sent_at' => now()->timestamp,
    ]);

    expect($ana->fresh()->hasOptedOutOfBroadcasts())->toBeTrue();
});

test('a stop word buried in a sentence is not an opt-out', function () {
    expect(OptOutDetector::isStopWord('PARAR'))->toBeTrue()
        ->and(OptOutDetector::isStopWord('  parar! '))->toBeTrue()
        ->and(OptOutDetector::isStopWord('Cancelar'))->toBeTrue()
        ->and(OptOutDetector::isStopWord('sair'))->toBeTrue()
        // The ones that would otherwise unsubscribe a customer who meant the
        // opposite, or was talking about their order.
        ->and(OptOutDetector::isStopWord('não quero parar de receber'))->toBeFalse()
        ->and(OptOutDetector::isStopWord('vou cancelar meu pedido'))->toBeFalse()
        ->and(OptOutDetector::isStopWord(''))->toBeFalse()
        ->and(OptOutDetector::isStopWord(null))->toBeFalse();
});

test('an outgoing message that happens to say PARAR changes nothing', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana');

    $conversation = Conversation::create([
        'contact_id' => $ana->id,
        'connection_id' => $connection->id,
        'external_id' => $ana->external_id,
        'status' => ConversationStatus::Active,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Text,
        'body' => 'PARAR',
        'sent_at' => now()->timestamp,
    ]);

    expect($ana->fresh()->hasOptedOutOfBroadcasts())->toBeFalse();
});

test('an agent can record and undo an opt-out by hand', function () {
    $user = BroadcastFixtures::user(['broadcasts.view', 'contacts.update']);
    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana');

    $this->actingAs($user)
        ->putJson("/api/contacts/{$ana->id}", ['broadcast_opted_out' => true])
        ->assertOk()
        ->assertJsonPath('contact.broadcast_opted_out', true);

    $this->actingAs($user)
        ->putJson("/api/contacts/{$ana->id}", ['broadcast_opted_out' => false])
        ->assertOk()
        ->assertJsonPath('contact.broadcast_opted_out', false);

    // Recording an opt-out must not lock the contact's name as a side effect.
    expect($ana->fresh()->name_locked)->toBeFalsy();
});
