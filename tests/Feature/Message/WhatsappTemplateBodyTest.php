<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Named for this file: Pest loads every test file into one process, so a helper
// sharing a name with a neighbour's is a fatal redeclare.
function templateBodyConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'phone_number_id' => '111000111',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
        ],
    ]);
}

function templateBodyConversation(Connection $connection): Conversation
{
    $contact = Contact::create([
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'username' => '5511999999999',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'status' => ConversationStatus::Active,
    ]);
}

/** @param array<int, array<string, mixed>> $templates */
function templateBodyFake(array $templates, int $listStatus = 200): void
{
    Http::fake([
        'graph.facebook.com/v25.0/222000222/message_templates*' => $listStatus === 200
            ? Http::response(['data' => $templates])
            : Http::response(['error' => ['message' => 'down']], $listStatus),
        'graph.facebook.com/v25.0/111000111/messages' => Http::response(['messages' => [['id' => 'wamid.TPL']]]),
    ]);
}

function templateBodyTemplate(string $text, string $language = 'pt_BR', string $name = 'chamado'): array
{
    return [
        'name' => $name,
        'language' => $language,
        'status' => 'APPROVED',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Suporte'],
            ['type' => 'BODY', 'text' => $text],
            ['type' => 'FOOTER', 'text' => 'ProxyBR'],
        ],
    ];
}

test('a template send records what the customer read, not the template name', function () {
    templateBodyFake([templateBodyTemplate('Olá! Tudo bem com você? Sou do suporte ProxyBR.')]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
    ]);

    expect($message->body)->toBe('Olá! Tudo bem com você? Sou do suporte ProxyBR.');
    // The payload that produced it stays recorded alongside.
    expect($message->meta['template']['name'])->toBe('chamado');
});

test('numbered variables are filled from the components in order', function () {
    templateBodyFake([templateBodyTemplate('Olá, {{1}}! Seu pedido {{2}} chega em {{3}}.')]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
        'components' => [[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => 'Ana'],
                ['type' => 'text', 'text' => '#48213'],
                ['type' => 'text', 'text' => 'sexta-feira'],
            ],
        ]],
    ]);

    expect($message->body)->toBe('Olá, Ana! Seu pedido #48213 chega em sexta-feira.');
});

test('named variables are filled by name, not by position', function () {
    templateBodyFake([templateBodyTemplate('Oi, {{first_name}}! A partir de {{price}}.')]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
        'components' => [[
            'type' => 'body',
            'parameters' => [
                // Deliberately out of the order they appear in the text.
                ['type' => 'text', 'parameter_name' => 'price', 'text' => 'R$ 89,90'],
                ['type' => 'text', 'parameter_name' => 'first_name', 'text' => 'Ana'],
            ],
        ]],
    ]);

    expect($message->body)->toBe('Oi, Ana! A partir de R$ 89,90.');
});

test('a value holding a price survives substitution', function () {
    // "R$ 8" reads as a backreference to a regex engine. A replacement string
    // would silently eat it; the callback form is why this passes.
    templateBodyFake([templateBodyTemplate('Total: {{1}}. Obrigado, {{2}}!')]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
        'components' => [[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => 'R$ 89,90'],
                ['type' => 'text', 'text' => 'C:\\Ana'],
            ],
        ]],
    ]);

    expect($message->body)->toBe('Total: R$ 89,90. Obrigado, C:\\Ana!');
});

test('an unfilled variable stays visible rather than becoming an empty gap', function () {
    templateBodyFake([templateBodyTemplate('Olá, {{1}}! Pedido {{2}}.')]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
        'components' => [[
            'type' => 'body',
            'parameters' => [['type' => 'text', 'text' => 'Ana'], ['type' => 'text', 'text' => '']],
        ]],
    ]);

    expect($message->body)->toBe('Olá, Ana! Pedido {{2}}.');
});

test('the language picks between two templates sharing a name', function () {
    templateBodyFake([
        templateBodyTemplate('Hello! This is ProxyBR support.', 'en_US'),
        templateBodyTemplate('Olá! Sou do suporte ProxyBR.', 'pt_BR'),
    ]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'en_US',
    ]);

    expect($message->body)->toBe('Hello! This is ProxyBR support.');
});

test('a template nobody can find keeps the name, so the message is never lost', function () {
    templateBodyFake([templateBodyTemplate('Olá!', 'pt_BR', 'outro_modelo')]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
    ]);

    expect($message->body)->toBe('chamado');
});

test('Meta being down costs the wording, never the send', function () {
    templateBodyFake([], 500);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
    ]);

    expect($message->body)->toBe('chamado');
});

test('the template list is read once per WABA, not once per send', function () {
    templateBodyFake([templateBodyTemplate('Olá! Sou do suporte ProxyBR.')]);

    $connection = templateBodyConnection();
    $conversation = templateBodyConversation($connection);
    $service = new MessageService();

    foreach (range(1, 3) as $ignored) {
        $service->sendTemplate($conversation, ['template_name' => 'chamado', 'language' => 'pt_BR']);
    }

    $reads = 0;
    Http::assertSent(function ($request) use (&$reads) {
        if (str_contains($request->url(), 'message_templates')) {
            $reads++;
        }

        return true;
    });

    expect($reads)->toBe(1);
});

test('a failed read is not cached, so the next send tries again', function () {
    $connection = templateBodyConnection();
    $conversation = templateBodyConversation($connection);
    $service = new MessageService();

    // One stub whose answer changes: Http::fake() adds stubs rather than
    // replacing them, so a second call would leave the first one winning.
    $healthy = false;
    Http::fake([
        'graph.facebook.com/v25.0/222000222/message_templates*' => function () use (&$healthy) {
            return $healthy
                ? Http::response(['data' => [templateBodyTemplate('Olá! Sou do suporte ProxyBR.')]])
                : Http::response(['error' => ['message' => 'down']], 500);
        },
        'graph.facebook.com/v25.0/111000111/messages' => Http::response(['messages' => [['id' => 'wamid.TPL']]]),
    ]);

    $first = $service->sendTemplate($conversation, ['template_name' => 'chamado', 'language' => 'pt_BR']);

    // Meta recovers. Had the empty result been cached, this would still be
    // labelled with the template's name for the next fifteen minutes.
    $healthy = true;
    $second = $service->sendTemplate($conversation, ['template_name' => 'chamado', 'language' => 'pt_BR']);

    expect($first->body)->toBe('chamado');
    expect($second->body)->toBe('Olá! Sou do suporte ProxyBR.');
});

test('a template with buttons is recorded as the interactive message it was', function () {
    templateBodyFake([[
        'name' => 'chamado',
        'language' => 'pt_BR',
        'status' => 'APPROVED',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Olá, {{1}}'],
            ['type' => 'BODY', 'text' => 'Sou do suporte ProxyBR. Você já é cliente?'],
            ['type' => 'FOOTER', 'text' => 'ProxyBR'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Já sou Cliente'],
                ['type' => 'QUICK_REPLY', 'text' => 'Não sou cliente'],
            ]],
        ],
    ]]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
        'components' => [
            ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'Ana']]],
        ],
    ]);

    $interactive = $message->meta['interactive'];

    expect($message->body)->toBe('Sou do suporte ProxyBR. Você já é cliente?')
        ->and($interactive['body']['text'])->toBe('Sou do suporte ProxyBR. Você já é cliente?')
        ->and($interactive['footer']['text'])->toBe('ProxyBR')
        // The header's own variable is filled from the header component.
        ->and($interactive['header']['text'])->toBe('Olá, Ana')
        ->and(array_column(array_column($interactive['action']['buttons'], 'reply'), 'title'))
        ->toBe(['Já sou Cliente', 'Não sou cliente']);
});

test('every kind of button is drawn, since the customer taps them all the same', function () {
    templateBodyFake([[
        'name' => 'chamado',
        'language' => 'pt_BR',
        'status' => 'APPROVED',
        'components' => [
            ['type' => 'BODY', 'text' => 'Sua oferta chegou.'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Ver a coleção', 'url' => 'https://example.com'],
                ['type' => 'PHONE_NUMBER', 'text' => 'Ligar', 'phone_number' => '5511999999999'],
                ['type' => 'COPY_CODE', 'text' => 'Copiar código'],
            ]],
        ],
    ]]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
    ]);

    expect(array_column(array_column($message->meta['interactive']['action']['buttons'], 'reply'), 'title'))
        ->toBe(['Ver a coleção', 'Ligar', 'Copiar código']);
});

test('a body-only template stays a plain text bubble', function () {
    templateBodyFake([[
        'name' => 'chamado',
        'language' => 'pt_BR',
        'status' => 'APPROVED',
        'components' => [['type' => 'BODY', 'text' => 'Olá! Sou do suporte ProxyBR.']],
    ]]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
    ]);

    // Nothing the text bubble cannot already show, so no second shape is kept.
    expect($message->body)->toBe('Olá! Sou do suporte ProxyBR.')
        ->and($message->meta)->not->toHaveKey('interactive');
});

test('a template the panel cannot name still carries its buttons', function () {
    // The wording is unreadable (no BODY), but the buttons are still what the
    // customer was shown — losing them too would be a second loss.
    templateBodyFake([[
        'name' => 'chamado',
        'language' => 'pt_BR',
        'status' => 'APPROVED',
        'components' => [['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Falar com atendente']]]],
    ]]);

    $connection = templateBodyConnection();

    $message = (new MessageService())->sendTemplate(templateBodyConversation($connection), [
        'template_name' => 'chamado',
        'language' => 'pt_BR',
    ]);

    expect($message->body)->toBe('chamado')
        ->and($message->meta['interactive'])->not->toHaveKey('body')
        ->and($message->meta['interactive']['action']['buttons'][0]['reply']['title'])->toBe('Falar com atendente');
});
