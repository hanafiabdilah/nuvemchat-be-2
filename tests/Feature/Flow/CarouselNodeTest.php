<?php

use App\Services\Flow\InteractiveNodes;

/** A two-card quick-reply carousel; one button id is left for the fallback. */
function carouselNodeData(array $overrides = []): array
{
    return array_merge([
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
    ], $overrides);
}

test('carousel options flatten across cards, falling back to a positional id', function () {
    expect(InteractiveNodes::options(carouselNodeData()))->toBe([
        ['id' => 'card_x1', 'title' => 'Saber mais'],
        ['id' => 'card_1_btn_2', 'title' => 'Favoritar'],
        ['id' => 'card_y1', 'title' => 'Saber mais'],
    ]);
});

test('a link-out carousel has no branches and does not wait for a reply', function () {
    $data = carouselNodeData(['card_button_type' => 'cta_url']);
    $data['cards'][0] += ['button_label' => 'Abrir', 'button_url' => 'https://example.com/1'];
    $data['cards'][1] += ['button_label' => 'Abrir', 'button_url' => 'https://example.com/2'];

    expect(InteractiveNodes::options($data))->toBe([])
        ->and(InteractiveNodes::awaitsReply($data))->toBeFalse()
        // Nothing to branch on is fine here — it is still worth sending.
        ->and(InteractiveNodes::isSendable($data))->toBeTrue();
});

test('a quick-reply carousel waits for the tap that picks its branch', function () {
    expect(InteractiveNodes::awaitsReply(carouselNodeData()))->toBeTrue();
});

test('a carousel below the card floor, or without a body, is not sendable', function () {
    $oneCard = carouselNodeData();
    array_pop($oneCard['cards']);

    expect(InteractiveNodes::isSendable($oneCard))->toBeFalse()
        ->and(InteractiveNodes::isSendable(carouselNodeData(['body' => '  '])))->toBeFalse()
        ->and(InteractiveNodes::isSendable(carouselNodeData()))->toBeTrue();
});

test('a card with no media is dropped, and takes the carousel below the floor with it', function () {
    $data = carouselNodeData();
    $data['cards'][1]['header_url'] = '';

    expect(InteractiveNodes::cards($data))->toHaveCount(1)
        ->and(InteractiveNodes::isSendable($data))->toBeFalse();
});

test('one unfinished card makes the whole carousel unsendable', function () {
    // Meta refuses a carousel whose cards disagree, so a card left without its
    // buttons has to stop the send rather than reach the API and fail there.
    $noButtons = carouselNodeData();
    $noButtons['cards'][1]['buttons'] = [];

    $noUrl = carouselNodeData(['card_button_type' => 'cta_url']);
    $noUrl['cards'][0] += ['button_label' => 'Abrir', 'button_url' => 'https://example.com/1'];
    $noUrl['cards'][1] += ['button_label' => 'Abrir', 'button_url' => ''];

    expect(InteractiveNodes::isSendable($noButtons))->toBeFalse()
        ->and(InteractiveNodes::isSendable($noUrl))->toBeFalse();
});

test('a card button id survives into the send payload, so the branch still matches', function () {
    $payload = InteractiveNodes::sendPayload(carouselNodeData());

    expect($payload['interactive_type'])->toBe('carousel')
        ->and($payload['card_button_type'])->toBe('quick_reply')
        ->and($payload['cards'][0]['buttons'][0]['id'])->toBe('card_x1')
        ->and($payload['cards'][0]['buttons'][1]['id'])->toBe('card_1_btn_2')
        // A carousel carries no header or footer of its own.
        ->and($payload)->not->toHaveKey('header')
        ->and($payload)->not->toHaveKey('footer');
});

test('a link-out card defaults its label but never invents a URL', function () {
    $data = carouselNodeData(['card_button_type' => 'cta_url']);
    $data['cards'][0]['button_url'] = 'https://example.com/1';

    $cards = InteractiveNodes::cards($data);

    expect($cards[0]['button_label'])->toBe('Open')
        ->and($cards[0]['button_url'])->toBe('https://example.com/1')
        ->and($cards[1]['button_url'])->toBe('');
});

test('a tapped carousel button is matched to its card branch', function () {
    $data = carouselNodeData();

    expect(InteractiveNodes::matchOption($data, 'card_y1', 'Saber mais'))->toBe('card_y1')
        ->and(InteractiveNodes::matchOption($data, 'card_1_btn_2', 'Favoritar'))->toBe('card_1_btn_2')
        ->and(InteractiveNodes::matchOption($data, null, 'Favoritar'))->toBe('card_1_btn_2')
        ->and(InteractiveNodes::matchOption($data, null, 'nada disso'))->toBeNull();
});

test('a reply id is read from whatever *_reply key the webhook used', function () {
    expect(InteractiveNodes::replyFromWebhook(['type' => 'button_reply', 'button_reply' => ['id' => 'card_x1', 'title' => 'Saber mais']]))
        ->toMatchArray(['id' => 'card_x1', 'title' => 'Saber mais']);

    expect(InteractiveNodes::replyFromWebhook(['list_reply' => ['id' => 'row_1', 'title' => 'Basic', 'description' => 'Cheap']]))
        ->toMatchArray(['id' => 'row_1', 'title' => 'Basic', 'description' => 'Cheap']);

    // A shape Meta has not shipped yet still yields its id rather than nothing.
    expect(InteractiveNodes::replyFromWebhook(['carousel_reply' => ['id' => 'card_z9', 'title' => 'Ver']]))
        ->toMatchArray(['id' => 'card_z9', 'title' => 'Ver']);

    expect(InteractiveNodes::replyFromWebhook(['type' => 'button_reply']))->toBeNull()
        ->and(InteractiveNodes::replyFromWebhook(null))->toBeNull();
});
