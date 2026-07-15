<?php

use App\Services\Message\Handlers\WhatsappOfficialHandler;

function buildInteractive(array $data): array
{
    $handler = new WhatsappOfficialHandler();
    $ref = new ReflectionMethod($handler, 'buildInteractivePayload');
    $ref->setAccessible(true);

    return $ref->invoke($handler, $data);
}

test('builds a reply-button payload with auto ids and header/footer', function () {
    $payload = buildInteractive([
        'interactive_type' => 'button',
        'body' => 'Pick one',
        'header' => 'Hi',
        'footer' => 'Thanks',
        'buttons' => [
            ['title' => 'Yes'],
            ['title' => 'No', 'id' => 'no_custom'],
        ],
    ]);

    expect($payload['type'])->toBe('button');
    expect($payload['body'])->toBe(['text' => 'Pick one']);
    expect($payload['header'])->toBe(['type' => 'text', 'text' => 'Hi']);
    expect($payload['footer'])->toBe(['text' => 'Thanks']);
    expect($payload['action']['buttons'])->toBe([
        ['type' => 'reply', 'reply' => ['id' => 'btn_1', 'title' => 'Yes']],
        ['type' => 'reply', 'reply' => ['id' => 'no_custom', 'title' => 'No']],
    ]);
});

test('builds a list payload with sections, rows and auto ids', function () {
    $payload = buildInteractive([
        'interactive_type' => 'list',
        'body' => 'Menu',
        'button_label' => 'Open',
        'sections' => [
            [
                'title' => 'Drinks',
                'rows' => [
                    ['title' => 'Coffee', 'description' => 'Hot'],
                    ['title' => 'Tea'],
                ],
            ],
        ],
    ]);

    expect($payload['type'])->toBe('list');
    expect($payload['action']['button'])->toBe('Open');
    expect($payload['action']['sections'][0]['title'])->toBe('Drinks');
    expect($payload['action']['sections'][0]['rows'][0])->toBe([
        'id' => 'row_1_1',
        'title' => 'Coffee',
        'description' => 'Hot',
    ]);
    // Row without description omits the key.
    expect($payload['action']['sections'][0]['rows'][1])->toBe([
        'id' => 'row_1_2',
        'title' => 'Tea',
    ]);
});

test('omits header/footer when not provided', function () {
    $payload = buildInteractive([
        'interactive_type' => 'button',
        'body' => 'x',
        'buttons' => [['title' => 'Ok']],
    ]);

    expect($payload)->not->toHaveKey('header');
    expect($payload)->not->toHaveKey('footer');
});
