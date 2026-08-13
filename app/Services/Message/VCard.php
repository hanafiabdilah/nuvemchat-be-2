<?php

namespace App\Services\Message;

/**
 * Just enough vCard to render a shared contact card.
 *
 * WhatsApp sends the card as a raw vCard string; there is no structured copy
 * of it in the webhook, so the name and the numbers have to come out of the
 * text. This reads the two properties a chat bubble actually shows (FN and
 * TEL) and ignores the rest of the format — photos, addresses, org fields and
 * the parts a full parser would have to care about are not rendered anywhere,
 * and the untouched `vcard` string travels alongside for anyone who wants it.
 *
 * The shapes to survive, all seen in real WhatsApp payloads:
 *
 *   FN:John Doe
 *   item1.TEL;waid=6281234567890:+62 812-3456-7890
 *   TEL;type=CELL;type=VOICE;waid=5511999999999:+55 11 99999-9999
 *
 * `waid` is the number's WhatsApp id — the one worth keeping, because it is
 * already normalised and is what an outbound message would be addressed to.
 */
class VCard
{
    /**
     * @return array{name: ?string, phones: array<int, array{number: string, wa_id: ?string}>}
     */
    public static function parse(string $vcard): array
    {
        // Folded lines: a vCard continues a long value on the next line when it
        // starts with a space or tab. Unfold before anything else or a wrapped
        // number arrives in two useless halves.
        $unfolded = preg_replace("/\r\n[ \t]|\n[ \t]/", '', $vcard) ?? $vcard;
        $lines = preg_split('/\r\n|\n|\r/', $unfolded) ?: [];

        $name = null;
        $phones = [];

        foreach ($lines as $line) {
            $separator = strpos($line, ':');

            if ($separator === false) {
                continue;
            }

            $property = substr($line, 0, $separator);
            $value = trim(substr($line, $separator + 1));

            if ($value === '') {
                continue;
            }

            // Property names may carry a grouping prefix ("item1.TEL") and
            // parameters (";waid=...;type=CELL"), in any order.
            $parameters = explode(';', $property);
            $bareName = strtoupper(preg_replace('/^[^.]*\./', '', array_shift($parameters) ?? ''));

            if ($bareName === 'FN' && $name === null) {
                $name = $value;

                continue;
            }

            if ($bareName !== 'TEL') {
                continue;
            }

            $waId = null;

            foreach ($parameters as $parameter) {
                if (stripos($parameter, 'waid=') === 0) {
                    $waId = substr($parameter, 5);
                }
            }

            $phones[] = ['number' => $value, 'wa_id' => $waId ?: null];
        }

        return ['name' => $name, 'phones' => $phones];
    }

    /**
     * The card as the UI shows it: the display name WhatsApp sent when there
     * is one, falling back to the vCard's own FN — they usually agree, but the
     * webhook's displayName is missing often enough to need the fallback.
     *
     * @return array{name: string, phones: array<int, array{number: string, wa_id: ?string}>, vcard: string}
     */
    public static function toCard(?string $displayName, string $vcard): array
    {
        $parsed = self::parse($vcard);

        return [
            'name' => $displayName ?: ($parsed['name'] ?? ''),
            'phones' => $parsed['phones'],
            'vcard' => $vcard,
        ];
    }

    /**
     * The shared contact cards inside a whatsmeow `Message` node, in the order
     * WhatsApp sent them.
     *
     * Two payload shapes carry the same thing — `contactMessage` for a single
     * card, `contactsArrayMessage` for several — so both flatten to one list
     * and nothing downstream has to care which arrived. Lives here rather than
     * in the webhook handler because the API resource re-reads it off the
     * stored payload every time a message is serialized.
     *
     * @return array<int, array{name: string, phones: array, vcard: string}>
     */
    public static function cardsFrom(array $message): array
    {
        if (isset($message['contactMessage'])) {
            $card = $message['contactMessage'];

            return isset($card['vcard'])
                ? [self::toCard($card['displayName'] ?? null, $card['vcard'])]
                : [];
        }

        $cards = $message['contactsArrayMessage']['contacts'] ?? null;

        if (! is_array($cards)) {
            return [];
        }

        return array_values(array_map(
            fn (array $card) => self::toCard($card['displayName'] ?? null, $card['vcard'] ?? ''),
            array_filter($cards, fn ($card) => is_array($card) && isset($card['vcard'])),
        ));
    }
}
