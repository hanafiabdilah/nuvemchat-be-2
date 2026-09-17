<?php

namespace App\Services\Flow;

/**
 * How an Interactive node reaches a channel that cannot draw tappable buttons.
 *
 * Reply buttons, list menus and carousels are a WhatsApp Cloud API feature, and
 * for a long time that made the node itself WhatsApp-Official-only: a flow that
 * used one refused to save against a Telegram connection. But the node is not
 * really "a WhatsApp widget" — it is *a menu with one branch per choice*, and
 * every channel we speak can put a menu in front of somebody. What they cannot
 * do is make it tappable.
 *
 * So the node now runs everywhere and only its rendering changes. The branching
 * needed nothing: {@see InteractiveNodes::options()} already flattens all three
 * kinds into one ordered list, and {@see InteractiveNodes::matchOption()} has
 * always accepted a typed number as well as a tap — which is why somebody
 * replying "2" on Telegram lands on exactly the branch a tap would have taken.
 *
 * Two rules shape everything below:
 *
 *  - **The numbers here and the numbers matchOption() counts must be the same
 *    numbers.** Both come from `options()` order, and each line looks its own
 *    number up by option id rather than counting again, so a row skipped in one
 *    place cannot shift the other.
 *  - **Nothing that is only decoration survives, and nothing that can be sent
 *    is thrown away.** A list keeps its section headings, because sections are
 *    what make a 30-row menu readable; its "Menu" button label is dropped,
 *    because there is no button. A carousel's cards go out as one media message
 *    each rather than as a text bubble full of image URLs — the pictures are
 *    the reason the author chose a carousel, and every target channel can send
 *    pictures.
 *
 * Deliberately no markdown: `*bold*` renders on WhatsApp, needs a parse_mode on
 * Telegram, wants `**` on Discord and shows up literally on Instagram. A bare
 * heading line above numbered rows reads correctly everywhere.
 *
 * Mirrored in `lib/interactiveFallback.ts`, which previews this in the builder.
 * A preview that disagrees with what is sent is worse than none, so the two
 * must be changed together.
 */
final class InteractiveFallback
{
    /**
     * The node rendered as a list of message bubbles, in send order.
     *
     * Each entry is the shape `FlowExecutor::sendByMessageType()` dispatches on
     * — `message_type` plus `body` plus, for media, `attachment_url` — so the
     * executor only has to loop.
     *
     * @param  array<string, mixed>  $data  interpolated node data
     * @return list<array{message_type: string, body: string, attachment_url: ?string}>
     */
    public static function bubbles(array $data): array
    {
        return match (InteractiveNodes::type($data)) {
            'carousel' => self::carousel($data),
            'list' => [self::text(self::listLines($data))],
            default => [self::text(self::buttonLines($data))],
        };
    }

    /**
     * Reply buttons: header, body, the numbered choices, footer.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function buttonLines(array $data): array
    {
        $lines = self::intro($data);

        if (($options = InteractiveNodes::options($data)) !== []) {
            $lines[] = '';

            foreach ($options as $i => $option) {
                $lines[] = ($i + 1).'. '.$option['title'];
            }
        }

        return self::withFooter($lines, $data);
    }

    /**
     * A list menu, section headings and row descriptions intact.
     *
     * Numbering runs straight through the sections rather than restarting in
     * each one: the number *is* the branch key, so two rows called "2" would
     * make a typed answer ambiguous.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function listLines(array $data): array
    {
        $lines = self::intro($data);
        $numbers = InteractiveNodes::optionNumbers($data);

        foreach (array_values((array) ($data['sections'] ?? [])) as $si => $section) {
            $rows = [];

            foreach (array_values((array) ($section['rows'] ?? [])) as $ri => $row) {
                $number = $numbers[InteractiveNodes::optionId($row, 'row_'.($si + 1).'_'.($ri + 1))] ?? null;

                // Absent means options() skipped this row (an empty title), so
                // it has no branch and must not take a number here either.
                if ($number === null) {
                    continue;
                }

                $rows[] = $number.'. '.trim((string) ($row['title'] ?? ''));

                if (($description = trim((string) ($row['description'] ?? ''))) !== '') {
                    $rows[] = '   '.$description;
                }
            }

            if ($rows === []) {
                continue;
            }

            $lines[] = '';

            if (($title = trim((string) ($section['title'] ?? ''))) !== '') {
                $lines[] = $title;
            }

            array_push($lines, ...$rows);
        }

        return self::withFooter($lines, $data);
    }

    /**
     * A carousel: the body as its own bubble, then one media message per card
     * carrying that card's caption and — right next to the picture it belongs
     * to — the numbers of its own choices.
     *
     * The choices are deliberately not repeated in a summary at the end. On a
     * carousel the whole point is which picture a number belongs to, and a
     * recap ten bubbles later says the opposite.
     *
     * Link-out cards print their destination instead: there is no tap to wait
     * for, so a plain URL is the honest translation of that button.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{message_type: string, body: string, attachment_url: ?string}>
     */
    private static function carousel(array $data): array
    {
        $bubbles = [];
        $numbers = InteractiveNodes::optionNumbers($data);

        // A carousel carries no header or footer of its own — the Cloud API
        // rejects them — so the body is all there is to open with.
        if (($body = trim((string) ($data['body'] ?? ''))) !== '') {
            $bubbles[] = self::text([$body]);
        }

        foreach (InteractiveNodes::cards($data) as $card) {
            $caption = [];

            if (($cardBody = trim((string) ($card['body'] ?? ''))) !== '') {
                $caption[] = $cardBody;
            }

            if (($url = (string) ($card['button_url'] ?? '')) !== '') {
                $caption[] = $card['button_label'].': '.$url;
            }

            foreach ((array) ($card['buttons'] ?? []) as $button) {
                $number = $numbers[$button['id']] ?? null;

                if ($number === null) {
                    continue;
                }

                $caption[] = $number.'. '.$button['title'];
            }

            $bubbles[] = [
                'message_type' => ($card['header_type'] ?? 'image') === 'video' ? 'video' : 'image',
                'body' => implode("\n", $caption),
                'attachment_url' => $card['header_url'],
            ];
        }

        return $bubbles;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function intro(array $data): array
    {
        return array_values(array_filter([
            trim((string) ($data['header'] ?? '')),
            trim((string) ($data['body'] ?? '')),
        ], fn (string $line) => $line !== ''));
    }

    /**
     * The footer, kept off the last option by a blank line.
     *
     * It doubles as the instruction line here ("Responda com o número"), which
     * is why no such sentence is hard-coded: the engine has no idea what
     * language this conversation is in, and the author already has a field for
     * exactly this text.
     *
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function withFooter(array $lines, array $data): array
    {
        if (($footer = trim((string) ($data['footer'] ?? ''))) !== '') {
            $lines[] = '';
            $lines[] = $footer;
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @return array{message_type: string, body: string, attachment_url: ?string}
     */
    private static function text(array $lines): array
    {
        return [
            'message_type' => 'text',
            'body' => implode("\n", $lines),
            'attachment_url' => null,
        ];
    }
}
