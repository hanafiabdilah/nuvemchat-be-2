<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * GET /conversations/{id}/variables feeds the composer's {{tokens}}.
 * {{contact.phone}} used to be the contact's external id on every channel —
 * an e-mail address on e-mail, a platform id on Telegram, a group JID on a
 * WhatsApp group — and went to customers as "your number". It is now a phone
 * number only where the channel reaches people by one, and absent elsewhere
 * (the composer then sends it empty).
 */

/** @return array{0: User, 1: Conversation} */
function convVarsConversation(Channel $channel, string $externalId, bool $isGroup = false, ?string $username = null): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'Canal',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    $agent = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Marina']);
    $agent->connections()->syncWithoutDetaching([$connection->id]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => $externalId,
        'name' => 'Ana',
        'username' => $username,
        'channel' => $channel,
        'is_group' => $isGroup,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'user_id' => $agent->id,
        'external_id' => $contact->external_id,
        'type' => $isGroup ? ConversationType::Group : ConversationType::Private,
        'status' => ConversationStatus::Active,
    ]);

    return [$agent->fresh(), $conversation];
}

test('a WhatsApp contact offers their number as contact.phone', function () {
    [$agent, $conversation] = convVarsConversation(Channel::WhatsappApiway, '5511988887777');

    $data = $this->actingAs($agent, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/variables")
        ->assertOk()
        ->json('data');

    expect($data['contact.phone'])->toBe('5511988887777')
        ->and($data['contact.name'])->toBe('Ana')
        ->and($data['agent_name'])->toBe('Marina')
        // The raw code: the composer words it, the API has no language to pick.
        ->and($data['conversation.status'])->toBe('active')
        // No username on file → left out, and the composer sends it empty.
        ->and($data)->not->toHaveKey('contact.username');
});

test('channels that do not reach people by phone offer no contact.phone', function (Channel $channel, string $externalId) {
    [$agent, $conversation] = convVarsConversation($channel, $externalId);

    $data = $this->actingAs($agent, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/variables")
        ->assertOk()
        ->json('data');

    expect($data)->not->toHaveKey('contact.phone')
        ->and($data['contact.name'])->toBe('Ana');
})->with([
    'e-mail' => [Channel::Email, 'ana@example.com'],
    'telegram' => [Channel::Telegram, '123456789'],
    'instagram' => [Channel::Instagram, '17841400000000000'],
]);

test('a WhatsApp group offers no contact.phone — its id is a group, not a number', function () {
    [$agent, $conversation] = convVarsConversation(Channel::WhatsappApiway, '120363040000000000@g.us', isGroup: true);

    $data = $this->actingAs($agent, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/variables")
        ->assertOk()
        ->json('data');

    expect($data)->not->toHaveKey('contact.phone');
});
