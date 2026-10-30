<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\CallLog;
use App\Services\Webhook\ExternalAutomationReply;
use App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * ProxyBR's hub sends its renewal reminder on the same WABA Pingly serves, with
 * buttons whose ids start with "rnw:". Meta delivers the customer's tap to both
 * apps; the hub answers it, and Pingly must only file it.
 */
const EAR_WA_ID = '5511999990001';

beforeEach(function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]])]);
});

/** A WhatsApp Official connection whose flow greets: start → message. */
function earConnection(array $overrides = []): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Atendimento']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $hello = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Olá! Sou o Darwin.', 'message_type' => 'text'],
        'position_x' => 200,
        'position_y' => 0,
    ]);
    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $hello->id, 'condition_value' => null]);

    return Connection::create(array_merge([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => ['phone_number_id' => '106540352242922', 'access_token' => 'test-token', 'business_account_id' => '111'],
        'ignored_button_prefixes' => ['rnw:'],
    ], $overrides));
}

/** One Cloud API webhook entry carrying `$message`. */
function earEntry(array $message): array
{
    return [
        'id' => '111',
        'changes' => [[
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '551140000000', 'phone_number_id' => '106540352242922'],
                'contacts' => [['profile' => ['name' => 'Cliente'], 'wa_id' => EAR_WA_ID]],
                'messages' => [$message + [
                    'from' => EAR_WA_ID,
                    'id' => 'wamid.IN'.uniqid(),
                    'timestamp' => (string) now()->timestamp,
                ]],
            ],
            'field' => 'messages',
        ]],
    ];
}

/** A tap on a template's quick-reply button. */
function earTemplateTap(string $payload, string $text = 'Renovar agora'): array
{
    return earEntry([
        'type' => 'button',
        'context' => ['from' => '551140000000', 'id' => 'wamid.TEMPLATE-FROM-HUB'],
        'button' => ['payload' => $payload, 'text' => $text],
    ]);
}

/** A tap on an interactive message's reply button. */
function earButtonTap(string $id, string $title): array
{
    return earEntry([
        'type' => 'interactive',
        'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]],
    ]);
}

function earText(string $body): array
{
    return earEntry(['type' => 'text', 'text' => ['body' => $body]]);
}

function earDeliver(Connection $connection, array $entry): void
{
    (new WhatsappOfficialHandler)->handle($connection, $entry);
}

function earContact(Connection $connection): Contact
{
    return Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => EAR_WA_ID,
        'name' => 'Cliente',
        'channel' => $connection->channel,
    ]);
}

test('a renewal tap from a contact who never wrote is filed in a closed thread, without the flow', function () {
    $connection = earConnection();

    earDeliver($connection, earTemplateTap('rnw:renew:42'));

    $conversation = Conversation::sole();
    $message = Message::sole();

    expect($conversation->status)->toBe(ConversationStatus::Resolved)
        ->and($conversation->resolved_at)->not->toBeNull()
        ->and($conversation->user_id)->toBeNull()
        ->and($message->message_type)->toBe(MessageType::Interactive)
        ->and($message->body)->toBe('Renovar agora')
        ->and($message->read_at)->not->toBeNull()
        ->and($message->meta[ExternalAutomationReply::META_KEY])->toBe([
            'prefix' => 'rnw:',
            'id' => 'rnw:renew:42',
            'kind' => ExternalAutomationReply::KIND_BUTTON,
        ])
        ->and(FlowState::count())->toBe(0)
        // No greeting, no menu: nothing went out.
        ->and(Message::where('sender_type', SenderType::Outgoing)->count())->toBe(0);

    Http::assertNothingSent();
});

test('every tap of one renewal lands in the same thread', function () {
    $connection = earConnection();

    earDeliver($connection, earTemplateTap('rnw:renew:42'));
    earDeliver($connection, earButtonTap('rnw:confirm:42', 'Confirmar'));
    earDeliver($connection, earButtonTap('rnw:pix:42', 'Gerar Pix'));

    expect(Conversation::count())->toBe(1)
        ->and(Conversation::sole()->status)->toBe(ConversationStatus::Resolved)
        ->and(Message::orderBy('id')->pluck('body')->all())->toBe(['Renovar agora', 'Confirmar', 'Gerar Pix'])
        ->and(FlowState::count())->toBe(0);
});

test('a tap in an open thread with an agent is appended and changes nothing', function () {
    $connection = earConnection();
    $agent = User::factory()->create(['tenant_id' => $connection->tenant_id]);
    $conversation = Conversation::create([
        'contact_id' => earContact($connection)->id,
        'connection_id' => $connection->id,
        'external_id' => EAR_WA_ID,
        'user_id' => $agent->id,
        'status' => ConversationStatus::Active,
    ]);

    earDeliver($connection, earButtonTap('rnw:decline:42', 'Não vou renovar'));

    expect(Conversation::count())->toBe(1)
        ->and($conversation->fresh()->status)->toBe(ConversationStatus::Active)
        ->and($conversation->fresh()->user_id)->toBe($agent->id)
        ->and($conversation->messages()->sole()->body)->toBe('Não vou renovar')
        ->and(FlowState::count())->toBe(0);
});

test('a tap does not reopen the last agent\'s thread, even inside the tolerance', function () {
    $connection = earConnection(['return_to_last_agent' => true, 'return_to_last_agent_minutes' => 30]);
    $agent = User::factory()->create(['tenant_id' => $connection->tenant_id]);
    $agent->connections()->syncWithoutDetaching([$connection->id]);
    $agent->forceFill(['last_seen_at' => now()])->save();

    $previous = Conversation::create([
        'contact_id' => earContact($connection)->id,
        'connection_id' => $connection->id,
        'external_id' => EAR_WA_ID,
        'user_id' => $agent->id,
        'status' => ConversationStatus::Resolved,
        'resolved_at' => now()->subMinutes(2),
        'resolved_by_user_id' => $agent->id,
    ]);

    earDeliver($connection, earTemplateTap('rnw:renew:42'));

    $previous->refresh();

    expect(Conversation::count())->toBe(1)
        ->and($previous->status)->toBe(ConversationStatus::Resolved)
        ->and($previous->resolved_at)->not->toBeNull()
        ->and($previous->messages()->where('message_type', MessageType::Info)->exists())->toBeFalse()
        ->and($previous->messages()->sole()->body)->toBe('Renovar agora');
});

test('a WhatsApp Flows screen sent back is matched on its flow_token', function () {
    $connection = earConnection();

    earDeliver($connection, earEntry([
        'type' => 'interactive',
        'interactive' => [
            'type' => 'nfm_reply',
            'nfm_reply' => [
                'name' => 'flow',
                'body' => 'Sent',
                'response_json' => json_encode(['flow_token' => 'rnw:flow:42', 'plan' => 'monthly']),
            ],
        ],
    ]));

    $message = Message::sole();

    expect($message->body)->toBe('Sent')
        ->and($message->meta[ExternalAutomationReply::META_KEY]['kind'])->toBe(ExternalAutomationReply::KIND_FLOW)
        ->and(Conversation::sole()->status)->toBe(ConversationStatus::Resolved)
        ->and(FlowState::count())->toBe(0);

    // The form's answers are not handed to the dashboard, only its label.
    $meta = (new MessageResource($message->load('conversation.connection')))->resolve()['meta'];

    expect($meta['external_automation'])->toBeTrue()
        ->and($meta['interactive']['nfm_reply'])->toBe(['name' => 'flow', 'body' => 'Sent']);
});

test('a Flows reply whose response_json cannot be read is treated as the customer\'s own', function () {
    $connection = earConnection();

    earDeliver($connection, earEntry([
        'type' => 'interactive',
        'interactive' => [
            'type' => 'nfm_reply',
            'nfm_reply' => ['name' => 'flow', 'body' => 'Sent', 'response_json' => '{not json'],
        ],
    ]));

    expect(Message::where('sender_type', SenderType::Incoming)->sole()->meta)
        ->not->toHaveKey(ExternalAutomationReply::META_KEY)
        ->and(Conversation::sole()->status)->toBe(ConversationStatus::Pending)
        ->and(FlowState::count())->toBe(1);
});

test('the customer\'s next typed message opens a thread and runs the flow as usual', function () {
    $connection = earConnection();

    earDeliver($connection, earTemplateTap('rnw:renew:42'));
    earDeliver($connection, earText('Tive um problema no pagamento'));

    $threads = Conversation::orderBy('id')->get();

    expect($threads)->toHaveCount(2)
        ->and($threads[0]->status)->toBe(ConversationStatus::Resolved)
        ->and($threads[1]->status)->not->toBe(ConversationStatus::Resolved)
        ->and(FlowState::where('conversation_id', $threads[1]->id)->exists())->toBeTrue();
});

test('a tap in a thread nobody acted on does not take away the opening message', function () {
    $connection = earConnection();

    // A missed call opens a Pending thread with nothing said in it.
    CallLog::record($connection, EAR_WA_ID, 'Cliente', 'CALL-1', CallLog::MISSED, now());
    $conversation = Conversation::sole();

    earDeliver($connection, earButtonTap('rnw:confirm:42', 'Confirmar'));
    expect(FlowState::count())->toBe(0);

    earDeliver($connection, earText('oi'));

    expect(FlowState::where('conversation_id', $conversation->id)->exists())->toBeTrue();
});

test('without prefixes a template button is the connection\'s own, now with its text', function () {
    $connection = earConnection(['ignored_button_prefixes' => null]);

    earDeliver($connection, earTemplateTap('rnw:renew:42'));

    $message = Message::where('sender_type', SenderType::Incoming)->sole();

    expect($message->message_type)->toBe(MessageType::Interactive)
        ->and($message->body)->toBe('Renovar agora')
        ->and($message->meta)->not->toHaveKey(ExternalAutomationReply::META_KEY)
        ->and(Conversation::sole()->status)->toBe(ConversationStatus::Pending)
        ->and(FlowState::count())->toBe(1);

    $meta = (new MessageResource($message->load('conversation.connection')))->resolve()['meta'];

    expect($meta['interactive']['button_reply'])->toBe(['id' => 'rnw:renew:42', 'title' => 'Renovar agora'])
        ->and($meta)->not->toHaveKey('external_automation');
});

test('a template button labelled with a stop word does not opt the contact out of campaigns', function () {
    $connection = earConnection(['ignored_button_prefixes' => null]);

    earDeliver($connection, earTemplateTap('cancel-order', 'Cancelar'));

    expect(Contact::sole()->broadcast_opted_out_at)->toBeNull();
});

test('a button outside the prefixes still runs the flow', function () {
    $connection = earConnection();

    earDeliver($connection, earButtonTap('menu:support', 'Suporte Técnico'));

    expect(Message::where('sender_type', SenderType::Incoming)->sole()->meta)
        ->not->toHaveKey(ExternalAutomationReply::META_KEY)
        ->and(FlowState::count())->toBe(1);
});

/*
 * The setting, through PUT /connections/{id}.
 */

function earOwner(Connection $connection): User
{
    Role::findOrCreate('owner', 'web');
    Permission::findOrCreate('connections.update', 'web');

    $owner = User::where('tenant_id', $connection->tenant_id)->firstOrFail();
    $owner->assignRole('owner');
    $owner->givePermissionTo('connections.update');

    return $owner;
}

test('the prefixes are saved trimmed and without repeats, and read back', function () {
    $connection = earConnection(['ignored_button_prefixes' => null]);

    $this->actingAs(earOwner($connection), 'sanctum')
        ->putJson("/api/connections/{$connection->id}", [
            'name' => 'WA',
            'ignored_button_prefixes' => [' rnw: ', 'rnw:', '', 'nps:'],
        ])
        ->assertOk()
        ->assertJsonPath('data.ignored_button_prefixes', ['rnw:', 'nps:']);

    expect($connection->fresh()->ignoredButtonPrefixes())->toBe(['rnw:', 'nps:']);
});

test('an empty list clears the setting, and leaving it out keeps it', function () {
    $connection = earConnection();
    $owner = earOwner($connection);

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}", ['name' => 'WA renamed'])
        ->assertOk();

    expect($connection->fresh()->ignoredButtonPrefixes())->toBe(['rnw:']);

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}", ['name' => 'WA', 'ignored_button_prefixes' => []])
        ->assertOk();

    expect($connection->fresh()->ignored_button_prefixes)->toBeNull();
});

test('a prefix too short to mean anything is refused', function () {
    $connection = earConnection(['ignored_button_prefixes' => null]);

    $this->actingAs(earOwner($connection), 'sanctum')
        ->putJson("/api/connections/{$connection->id}", ['name' => 'WA', 'ignored_button_prefixes' => ['r']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ignored_button_prefixes.0');
});

test('the setting is refused on a channel that would never read it', function () {
    $connection = earConnection(['channel' => Channel::Telegram, 'ignored_button_prefixes' => null, 'credentials' => ['token' => 't']]);

    $this->actingAs(earOwner($connection), 'sanctum')
        ->putJson("/api/connections/{$connection->id}", ['name' => 'TG', 'ignored_button_prefixes' => ['rnw:']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ignored_button_prefixes');
});
