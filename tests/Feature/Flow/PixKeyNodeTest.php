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
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\PixKeyNodes;
use App\Support\PixBrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * start → pix_key → "send the receipt" message. Returns the conversation and
 * the nodes, so a test can say where the flow landed.
 */
function pixKeyFlowFixture(Channel $channel, array $nodeData = []): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Material']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $pix = $flow->nodes()->create([
        'type' => NodeType::PixKey,
        'data' => array_replace([
            'key_type' => 'cpf',
            'key' => '532.156.338-17',
            'merchant_name' => 'Mônica Esteves da Silva',
            'amount' => '{{valor}}',
            'message' => 'Faça o Pix de R$ 19,90, {{contact.name}}:',
            'image_url' => '',
            'description' => '',
        ], $nodeData),
        'position_x' => 100,
        'position_y' => 0,
    ]);
    $after = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Envie o comprovante, por favor.', 'message_type' => 'text'],
        'position_x' => 200,
        'position_y' => 0,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $pix->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $pix->id, 'target_node_id' => $after->id, 'condition_value' => null]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => $channel->value,
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => $channel === Channel::WhatsappOfficial
            ? ['phone_number_id' => '111000111', 'access_token' => 'wa-token', 'business_account_id' => '222000222']
            : ['token' => 'apiway-token', 'instance_id' => 'inst-1'],
    ]);

    $contact = Contact::create([
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'username' => '5511999999999',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'status' => ConversationStatus::Pending,
    ]);

    // The amount comes from a variable, the way a flow that asked "which
    // package?" earlier would hand it over.
    FlowState::create([
        'conversation_id' => $conversation->id,
        'flow_id' => $flow->id,
        'current_node_id' => $start->id,
        'state_data' => ['valor' => '19,90'],
    ]);

    return [$conversation, compact('start', 'pix', 'after')];
}

function runPixKeyFlow(Conversation $conversation, array $nodes): void
{
    $state = FlowState::where('conversation_id', $conversation->id)->firstOrFail();

    (fn () => $this->executeFromNode($state, $nodes['start']))->call(new FlowExecutor());
}

/** @return list<Message> */
function pixKeyOutgoing(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->orderBy('id')
        ->get()
        ->all();
}

test('the copy-and-paste code is the Banco Central format, CRC and all', function () {
    // The example the Banco Central publishes for a static code.
    expect(PixBrCode::build('123e4567-e12b-12d1-a456-426655440000', 'Fulano de Tal', null, 'BRASILIA'))
        ->toBe('00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865802BR5913Fulano de Tal6008BRASILIA62070503***63041D3D');

    // With an amount; the name is cut to the field's 25 bytes and loses its accents.
    $code = PixBrCode::build('53215633817', 'Mônica Esteves da Silva Pereira', 1990);

    expect($code)->toContain('540519.90')
        ->and($code)->toContain('5925Monica Esteves da Silva P')
        ->and(strlen($code))->toBeLessThanOrEqual(512);
});

test('keys are read in the directory spelling, and impossible ones are refused', function () {
    expect(PixKeyNodes::normalizeKey('cpf', '532.156.338-17'))->toBe('53215633817')
        ->and(PixKeyNodes::normalizeKey('cpf', '1234'))->toBeNull()
        ->and(PixKeyNodes::normalizeKey('cnpj', '12.345.678/0001-95'))->toBe('12345678000195')
        ->and(PixKeyNodes::normalizeKey('phone', '(54) 99610-1458'))->toBe('+5554996101458')
        ->and(PixKeyNodes::normalizeKey('phone', '+55 54 99610-1458'))->toBe('+5554996101458')
        ->and(PixKeyNodes::normalizeKey('phone', '99610-1458'))->toBeNull()
        ->and(PixKeyNodes::normalizeKey('email', ' Financeiro@Loja.com.br '))->toBe('financeiro@loja.com.br')
        ->and(PixKeyNodes::normalizeKey('email', 'not-an-email'))->toBeNull()
        ->and(PixKeyNodes::normalizeKey('evp', '123E4567-E89B-12D3-A456-426614174000'))->toBe('123e4567-e89b-12d3-a456-426614174000')
        ->and(PixKeyNodes::normalizeKey('evp', 'abc'))->toBeNull();
});

test('on API Way the message goes first and then the Pix card through send-pix', function () {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'OUT-' . uniqid()]])]);

    [$conversation, $nodes] = pixKeyFlowFixture(Channel::WhatsappApiway, [
        'key_type' => 'phone',
        'key' => '(54) 99610-1458',
        'merchant_name' => 'Anderson',
    ]);

    runPixKeyFlow($conversation, $nodes);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/message/send-pix?instanceId=inst-1')
        && $request['phone'] === '5511999999999'
        && $request['merchantName'] === 'Anderson'
        && $request['pixKey'] === '+5554996101458'
        && $request['type'] === 'telefone');

    [$message, $card, $next] = pixKeyOutgoing($conversation);

    expect($message->body)->toBe('Faça o Pix de R$ 19,90, Ana:')
        ->and($card->message_type)->toBe(MessageType::Interactive)
        // The card itself shows no amount on this channel, and neither does its record.
        ->and($card->body)->toBe('Chave Pix (Celular): +5554996101458')
        ->and($card->meta['pix']['key'])->toBe('+5554996101458')
        ->and($card->meta['pix']['amount_cents'])->toBeNull()
        ->and($card->sent_by_flow_id)->not->toBeNull()
        // And the flow carried on.
        ->and($next->body)->toBe('Envie o comprovante, por favor.');

    expect(FlowState::where('conversation_id', $conversation->id)->first()->current_node_id)->toBe($nodes['after']->id);
});

test('without a name the card still goes out, as "Pix"', function () {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'OUT-' . uniqid()]])]);

    [$conversation, $nodes] = pixKeyFlowFixture(Channel::WhatsappApiway, ['merchant_name' => '', 'message' => '']);

    runPixKeyFlow($conversation, $nodes);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'send-pix') && $request['merchantName'] === 'Pix' && $request['type'] === 'cpf');
});

test('when the core refuses the card the key goes out as text, the message only once', function () {
    Http::fake([
        '*/v1/message/send-pix*' => Http::response(['success' => false, 'error' => 'not_supported'], 400),
        '*' => Http::response(['success' => true, 'data' => ['id' => 'TXT-' . uniqid()]]),
    ]);

    [$conversation, $nodes] = pixKeyFlowFixture(Channel::WhatsappApiway);

    runPixKeyFlow($conversation, $nodes);

    $bodies = array_map(fn (Message $message) => $message->body, pixKeyOutgoing($conversation));

    expect($bodies)->toBe([
        'Faça o Pix de R$ 19,90, Ana:',
        "Chave Pix (CPF): 53215633817\nNome: Mônica Esteves da Silva\nValor: R$ 19,90",
        '53215633817',
        'Envie o comprovante, por favor.',
    ]);
});

test('a card that may have gone out is never followed by a text copy', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'send-pix')) {
            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');
        }

        return Http::response(['success' => true, 'data' => ['id' => 'TXT-' . uniqid()]]);
    });

    [$conversation, $nodes] = pixKeyFlowFixture(Channel::WhatsappApiway);

    runPixKeyFlow($conversation, $nodes);

    // Only the message that preceded the card; and the flow waits on the node.
    expect(array_map(fn (Message $message) => $message->body, pixKeyOutgoing($conversation)))->toBe(['Faça o Pix de R$ 19,90, Ana:'])
        ->and(FlowState::where('conversation_id', $conversation->id)->first()->current_node_id)->toBe($nodes['pix']->id);
});

test('with the API Way card switched off the key goes out as text', function () {
    config(['services.apiway.pix' => false]);
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'TXT-' . uniqid()]])]);

    [$conversation, $nodes] = pixKeyFlowFixture(Channel::WhatsappApiway);

    runPixKeyFlow($conversation, $nodes);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'send-pix'));

    expect(count(pixKeyOutgoing($conversation)))->toBe(4);
});

test('on WhatsApp Official the key goes inside an order with a copy-and-paste code', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.' . uniqid()]]])]);

    [$conversation, $nodes] = pixKeyFlowFixture(Channel::WhatsappOfficial, [
        'image_url' => 'https://cdn.example.com/banner.jpg',
        'description' => 'Material completo em PDF',
    ]);

    runPixKeyFlow($conversation, $nodes);

    $sent = null;
    Http::assertSent(function ($request) use (&$sent) {
        if (($request['interactive']['type'] ?? null) !== 'order_details') {
            return false;
        }

        $sent = $request['interactive'];

        return true;
    });

    $parameters = $sent['action']['parameters'];
    $pix = $parameters['payment_settings'][0]['pix_dynamic_code'];

    expect($sent['action']['name'])->toBe('review_and_pay')
        ->and($sent['header']['image']['link'])->toBe('https://cdn.example.com/banner.jpg')
        ->and($sent['body']['text'])->toBe('Faça o Pix de R$ 19,90, Ana:')
        ->and($parameters['payment_type'])->toBe('br')
        ->and($parameters['total_amount'])->toBe(['value' => 1990, 'offset' => 100])
        ->and($parameters['order']['items'][0]['name'])->toBe('Material completo em PDF')
        ->and($pix['key'])->toBe('53215633817')
        ->and($pix['key_type'])->toBe('CPF')
        ->and($pix['merchant_name'])->toBe('Mônica Esteves da Silva')
        ->and($pix['code'])->toBe(PixBrCode::build('53215633817', 'Mônica Esteves da Silva', 1990));

    [$order, $next] = pixKeyOutgoing($conversation);

    expect($order->message_type)->toBe(MessageType::Interactive)
        ->and($next->body)->toBe('Envie o comprovante, por favor.');

    // The panel gets the card's facts, not the code built for Meta.
    $meta = (new MessageResource($order->fresh()))->toArray(request())['meta'];

    expect($meta['pix'])->toBe([
        'key_type' => 'cpf',
        'key' => '53215633817',
        'merchant_name' => 'Mônica Esteves da Silva',
        'amount_cents' => 1990,
        'currency' => 'BRL',
    ]);
});

test('on WhatsApp Official without an amount the key goes out as text', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.' . uniqid()]]])]);

    [$conversation, $nodes] = pixKeyFlowFixture(Channel::WhatsappOfficial, ['amount' => '']);

    runPixKeyFlow($conversation, $nodes);

    Http::assertNotSent(fn ($request) => ($request['interactive']['type'] ?? null) === 'order_details');

    expect(array_map(fn (Message $message) => $message->body, pixKeyOutgoing($conversation)))->toBe([
        'Faça o Pix de R$ 19,90, Ana:',
        "Chave Pix (CPF): 53215633817\nNome: Mônica Esteves da Silva",
        '53215633817',
        'Envie o comprovante, por favor.',
    ]);
});

test('when Meta refuses the order the key still reaches the customer as text', function () {
    Http::fake(function ($request) {
        if (($request['interactive']['type'] ?? null) === 'order_details') {
            return Http::response(['error' => ['message' => 'Payment not enabled for this account', 'code' => 131009]], 400);
        }

        return Http::response(['messages' => [['id' => 'wamid.' . uniqid()]]]);
    });

    [$conversation, $nodes] = pixKeyFlowFixture(Channel::WhatsappOfficial);

    runPixKeyFlow($conversation, $nodes);

    expect(array_map(fn (Message $message) => $message->body, pixKeyOutgoing($conversation)))->toBe([
        'Faça o Pix de R$ 19,90, Ana:',
        "Chave Pix (CPF): 53215633817\nNome: Mônica Esteves da Silva\nValor: R$ 19,90",
        '53215633817',
        'Envie o comprovante, por favor.',
    ]);
});

test('a node whose key cannot exist sends nothing and steps aside', function () {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'OUT-' . uniqid()]])]);

    [$conversation, $nodes] = pixKeyFlowFixture(Channel::WhatsappApiway, ['key' => '1234']);

    runPixKeyFlow($conversation, $nodes);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'send-pix'));

    expect(array_map(fn (Message $message) => $message->body, pixKeyOutgoing($conversation)))->toBe(['Envie o comprovante, por favor.']);
});

test('a half-built node saves, a key type that does not exist does not', function () {
    FlowBlueprint::validateNodes([['type' => 'pix_key', 'data' => PixKeyNodes::defaults()]]);

    expect(fn () => FlowBlueprint::validateNodes([['type' => 'pix_key', 'data' => ['key_type' => 'boleto']]]))
        ->toThrow(ValidationException::class);

    expect(FlowBlueprint::NODE_TYPES)->toContain('pix_key');
});
