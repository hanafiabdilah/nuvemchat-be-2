<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\AdReferral;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\FlowReceipt;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sales\AdReferrals;
use App\Services\Sales\SalesLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function salesOwner(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->givePermissionTo(Permission::findOrCreate('statistics.tenant.view', 'web'));

    return $user->fresh();
}

function salesConversation(User $owner, string $phone = '5511999999999'): Conversation
{
    $connection = Connection::firstOrCreate(
        ['tenant_id' => $owner->tenant_id, 'name' => 'WhatsApp'],
        ['channel' => Channel::WhatsappApiway, 'status' => ConnectionStatus::Active, 'credentials' => []],
    );

    $contact = Contact::create([
        'tenant_id' => $owner->tenant_id, 'channel' => Channel::WhatsappApiway,
        'external_id' => $phone, 'name' => 'Cliente '.$phone, 'username' => $phone,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id, 'connection_id' => $connection->id,
        'external_id' => $phone, 'status' => ConversationStatus::Pending,
    ]);
}

/** The customer's first message, as a click-to-WhatsApp ad delivers it. */
function salesAdClick(Conversation $conversation, string $adId = '120257190819850405'): void
{
    $conversation->messages()->create([
        'external_id' => 'AC'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Olá! quero saber mais sobre!',
        'sent_at' => now(),
        'meta' => ['Message' => ['extendedTextMessage' => ['text' => 'Olá!', 'contextInfo' => [
            'conversionSource' => 'FB_Ads',
            'externalAdReply' => ['sourceID' => $adId, 'sourceType' => 'ad', 'sourceURL' => 'https://fb.me/abc', 'title' => 'Converse conosco', 'ctwaClid' => 'clid-1'],
        ]]]],
    ]);
}

function salesSell(Conversation $conversation, int $cents, ?string $kind = null, ?string $offer = null): Sale
{
    return Sale::create([
        'tenant_id' => $conversation->connection->tenant_id,
        'connection_id' => $conversation->connection_id,
        'conversation_id' => $conversation->id,
        'contact_id' => $conversation->contact_id,
        'source' => Sale::SOURCE_RECEIPT,
        'source_id' => random_int(1, PHP_INT_MAX),
        'amount_cents' => $cents,
        'currency' => 'BRL',
        'kind' => $kind,
        'offer' => $offer,
        'ad_id' => AdReferrals::adIdFor($conversation),
        'sold_at' => now(),
    ]);
}

test('the first message of an ad lead records which ad it came from, once', function () {
    $owner = salesOwner();
    $conversation = salesConversation($owner);

    salesAdClick($conversation);
    salesAdClick($conversation, 'another-ad');

    expect(AdReferral::sole()->only(['ad_id', 'source_type', 'title', 'ctwa_clid']))->toBe([
        'ad_id' => '120257190819850405', 'source_type' => 'ad', 'title' => 'Converse conosco', 'ctwa_clid' => 'clid-1',
    ]);
});

test('the Cloud API referral is read too, and an ordinary message is not an ad', function () {
    expect(AdReferrals::read(['changes' => [['value' => ['messages' => [['referral' => [
        'source_id' => '999', 'source_type' => 'ad', 'source_url' => 'https://fb.me/x', 'headline' => 'Oferta', 'ctwa_clid' => 'c',
    ]]]]]]])['ad_id'])->toBe('999')
        ->and(AdReferrals::read(['Message' => ['conversation' => 'oi']]))->toBeNull()
        ->and(AdReferrals::read(['Message' => ['extendedTextMessage' => ['text' => 'oi', 'contextInfo' => ['quotedMessage' => []]]]]))->toBeNull();
});

test('an approved receipt becomes a sale labelled the way its node says, credited to the ad', function () {
    $owner = salesOwner();
    $conversation = salesConversation($owner);
    salesAdClick($conversation);

    $flow = App\Models\Flow::create(['tenant_id' => $owner->tenant_id, 'name' => 'Funil']);
    $node = $flow->nodes()->create([
        'type' => App\Enums\Flow\NodeType::Receipt,
        'data' => ['sale_kind' => 'upsell', 'sale_offer' => 'Kit Premium'],
        'position_x' => 0, 'position_y' => 0,
    ]);

    $receipt = FlowReceipt::create([
        'tenant_id' => $owner->tenant_id, 'conversation_id' => $conversation->id, 'flow_node_id' => $node->id,
        'status' => 'approved', 'amount_cents' => 4990, 'currency' => 'BRL',
    ]);

    SalesLedger::fromReceipt($receipt);
    SalesLedger::fromReceipt($receipt);

    expect(Sale::sole()->only(['amount_cents', 'kind', 'offer', 'ad_id', 'source']))->toBe([
        'amount_cents' => 4990, 'kind' => 'upsell', 'offer' => 'Kit Premium', 'ad_id' => '120257190819850405', 'source' => 'receipt',
    ]);
});

test('the sales page adds up revenue, ticket, kinds, offers and ads', function () {
    $owner = salesOwner();

    $fromAd = salesConversation($owner, '5511900000001');
    salesAdClick($fromAd);
    salesSell($fromAd, 10000, 'front', 'Curso');
    salesSell($fromAd, 5000, 'upsell', 'Mentoria');

    $leadOnly = salesConversation($owner, '5511900000002');
    salesAdClick($leadOnly);

    salesSell(salesConversation($owner, '5511900000003'), 3000);

    // Another workspace's sale must not appear.
    salesSell(salesConversation(salesOwner(), '5511900000004'), 99900, 'front', 'Alheio');

    Sanctum::actingAs($owner);
    $data = $this->getJson('/api/statistics/sales')->assertOk()->json('data');

    expect($data['totals'])->toBe(['revenue_cents' => 18000, 'sales' => 3, 'buyers' => 2, 'avg_ticket_cents' => 6000])
        ->and(collect($data['by_kind'])->pluck('revenue_cents', 'kind')->all())->toBe(['front' => 10000, 'upsell' => 5000, 'unclassified' => 3000])
        ->and(collect($data['by_offer'])->pluck('offer')->all())->toBe(['Curso', 'Mentoria'])
        ->and($data['ads']['leads'])->toBe(2)
        ->and($data['ads']['rows'][0])->toMatchArray(['ad_id' => '120257190819850405', 'leads' => 2, 'sales' => 2, 'revenue_cents' => 15000, 'conversion_pct' => 100.0])
        ->and(array_sum(array_column($data['daily'], 'revenue_cents')))->toBe(18000)
        ->and($data['goal'])->toBeNull();
});

test('a goal is set, measured over its own period, and cleared', function () {
    $owner = salesOwner();
    salesSell(salesConversation($owner), 25000);

    Sanctum::actingAs($owner);

    $this->putJson('/api/statistics/sales/goal', ['amount_cents' => 100000, 'period' => 'month'])
        ->assertOk()
        ->assertJsonPath('data.goal.revenue_cents', 25000)
        ->assertJsonPath('data.goal.pct', 25);

    // The page filtered to a day in the past still shows the month's progress.
    $this->getJson('/api/statistics/sales?from=2020-01-01&to=2020-01-02')
        ->assertJsonPath('data.totals.revenue_cents', 0)
        ->assertJsonPath('data.goal.revenue_cents', 25000);

    $this->putJson('/api/statistics/sales/goal', ['amount_cents' => 0])->assertOk()->assertJsonPath('data.goal', null);
});
