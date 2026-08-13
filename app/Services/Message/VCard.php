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
     * The shared contact cards inside a whatsmeow `Message` node (API Way), in
     * the order WhatsApp sent them.
     *
     * Two payload shapes carry the same thing — `contactMessage` for a single
     * card, `contactsArrayMessage` for several — so both flatten to one list
     * and nothing downstream has to care which arrived. Lives here rather than
     * in the webhook handler because the API resource re-reads it off the
     * stored payload every time a message is serialized.
     *
     * @return array<int, array{name: string, phones: array, vcard: string}>
     */
    public static function cardsFromWhatsmeow(array $message): array
    {
        if (isset($message['contactMessage'])) {
            $card = $message['contactMessage'];

            return isset($card['vcard'])
                ? [self::toCard($card['displayName'] ?? null, self::decode($card['vcard']))]
                : [];
        }

        $cards = $message['contactsArrayMessage']['contacts'] ?? null;

        if (! is_array($cards)) {
            return [];
        }

        return array_values(array_map(
            fn (array $card) => self::toCard($card['displayName'] ?? null, self::decode($card['vcard'] ?? '')),
            array_filter($cards, fn ($card) => is_array($card) && isset($card['vcard'])),
        ));
    }

    /**
     * The same cards from a Cloud API `messages[].contacts` array.
     *
     * Meta already sends the parts a bubble needs — `name.formatted_name` and
     * `phones[]` with the wa_id split out — so the vCard is only the fallback
     * for a card that arrived without them, and the parser above is what makes
     * that fallback possible at all.
     *
     * @return array<int, array{name: string, phones: array, vcard: string}>
     */
    public static function cardsFromCloudApi(array $contacts): array
    {
        return array_values(array_map(function (array $card) {
            $vcard = self::decode($card['vcard'] ?? '');
            $parsed = self::parse($vcard);

            $phones = array_values(array_map(
                fn (array $phone) => [
                    'number' => $phone['phone'],
                    'wa_id' => $phone['wa_id'] ?? null,
                ],
                array_filter(
                    $card['phones'] ?? [],
                    fn ($phone) => is_array($phone) && ($phone['phone'] ?? '') !== '',
                ),
            ));

            return [
                'name' => self::cloudApiName($card['name'] ?? []) ?? $parsed['name'] ?? '',
                'phones' => $phones !== [] ? $phones : $parsed['phones'],
                'vcard' => $vcard,
            ];
        }, array_filter($contacts, 'is_array')));
    }

    /** Meta's own formatting when it sent one, the name's parts otherwise. */
    private static function cloudApiName(array $name): ?string
    {
        if (($name['formatted_name'] ?? '') !== '') {
            return $name['formatted_name'];
        }

        $parts = array_filter([$name['first_name'] ?? null, $name['last_name'] ?? null]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * The card as text, whether or not it arrived base64-encoded.
     *
     * Meta's own examples show a plain vCard, but real webhooks have delivered
     * it base64'd — so this detects rather than assumes: decoding is only
     * accepted when the result actually looks like a vCard, because running
     * base64_decode over a plain card would quietly destroy it.
     */
    private static function decode(string $vcard): string
    {
        if ($vcard === '' || str_starts_with($vcard, 'BEGIN:VCARD')) {
            return $vcard;
        }

        $decoded = base64_decode($vcard, true);

        return ($decoded !== false && str_starts_with($decoded, 'BEGIN:VCARD')) ? $decoded : $vcard;
    }
}
