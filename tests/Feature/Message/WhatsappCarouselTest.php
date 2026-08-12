<?php

use App\Services\Message\Handlers\WhatsappOfficialHandler;

function buildCarouselPayload(array $data): array
{
    $handler = new WhatsappOfficialHandler();
    $ref = new ReflectionMethod($handler, 'buildInteractivePayload');
    $ref->setAccessible(true);

    return $ref->invoke($handler, $data);
}

test('builds a link-out carousel with indexed cards and no header or footer', function () {
    $payload = buildCarouselPayload([
        'interactive_type' => 'carousel',
        'body' => 'Ofertas 4.4 pra você!',
        // Deliberately supplied: a carousel must drop them, not pass them on.
        'header' => 'Ignored',
        'footer' => 'Ignored',
        'card_button_type' => 'cta_url',
        'cards' => [
            [
                'header_type' => 'image',
                'header_url' => 'https://cdn.example.com/1.jpg',
                'body' => 'Ofertas em destaque',
                'button_label' => 'Aproveitar agora',
                'button_url' => 'https://example.com/ofertas',
            ],
            [
                'header_type' => 'video',
                'header_url' => 'https://cdn.example.com/2.mp4',
                'body' => '',
                'button_label' => 'Ver mais',
                'button_url' => 'https://example.com/mais',
            ],
        ],
    ]);

    expect($payload['type'])->toBe('carousel')
        ->and($payload['body'])->toBe(['text' => 'Ofertas 4.4 pra você!'])
        ->and($payload)->not->toHaveKey('header')
        ->and($payload)->not->toHaveKey('footer');

    $cards = $payload['action']['cards'];

    expect($cards[0]['card_index'])->toBe(0)
        ->and($cards[1]['card_index'])->toBe(1)
        // Meta uses this one type for both button styles.
        ->and($cards[0]['type'])->toBe('cta_url')
        ->and($cards[0]['header'])->toBe(['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/1.jpg']])
        ->and($cards[1]['header'])->toBe(['type' => 'video', 'video' => ['link' => 'https://cdn.example.com/2.mp4']])
        ->and($cards[0]['body'])->toBe(['text' => 'Ofertas em destaque'])
        // An empty caption is omitted rather than sent blank.
        ->and($cards[1])->not->toHaveKey('body')
        ->and($cards[0]['action'])->toBe([
            'name' => 'cta_url',
            'parameters' => ['display_text' => 'Aproveitar agora', 'url' => 'https://example.com/ofertas'],
        ]);
});

test('builds a quick-reply carousel, filling in ids the author did not set', function () {
    $payload = buildCarouselPayload([
        'interactive_type' => 'carousel',
        'body' => 'Novidades',
        'card_button_type' => 'quick_reply',
        'cards' => [
            [
                'header_type' => 'image',
                'header_url' => 'https://cdn.example.com/1.jpg',
                'body' => 'Blue Echeveria',
                'buttons' => [
                    ['id' => 'card_x1', 'title' => 'Saber mais'],
                    ['title' => 'Favoritar'],
                ],
            ],
            [
                'header_type' => 'image',
                'header_url' => 'https://cdn.example.com/2.jpg',
                'body' => 'Cactus',
                'buttons' => [['id' => 'card_y1', 'title' => 'Saber mais']],
            ],
        ],
    ]);

    expect($payload['action']['cards'][0]['action']['buttons'])->toBe([
        ['type' => 'quick_reply', 'quick_reply' => ['id' => 'card_x1', 'title' => 'Saber mais']],
        ['type' => 'quick_reply', 'quick_reply' => ['id' => 'card_1_btn_2', 'title' => 'Favoritar']],
    ]);

    // Same label on a different card, different id — which is what makes the
    // branch unambiguous when the customer taps.
    expect($payload['action']['cards'][1]['action']['buttons'][0]['quick_reply']['id'])->toBe('card_y1');
});
