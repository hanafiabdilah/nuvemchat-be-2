<?php

namespace App\Services\Flow;

use App\Enums\Connection\Channel;
use App\Support\Money;

/**
 * The Pix key node ("Chave Pix"): sends the business's own Pix key as the
 * card WhatsApp draws for it, with a "Copiar chave Pix" button, and moves on.
 *
 * The other Pix node — Payment — charges through a gateway and waits for the
 * money. This one has no gateway and waits for nothing: it is for the sale
 * paid by a key the business types in, which used to go out as a plain text
 * message with the key pasted into a second bubble so it could be copied. It
 * usually sits right before a Receipt node.
 *
 * Three renderings of the same node, decided per channel:
 *
 *  - **WhatsApp API Way** — the native Pix card (`send-pix` in the core):
 *    name, key type, key, and the copy button. It carries nothing else, so
 *    the message goes out as its own bubble first and the amount is not on
 *    the card at all.
 *  - **WhatsApp Official** — an order (`order_details`, "Revisar e pagar")
 *    with the Pix inside it. Meta's version needs an amount and a
 *    copy-and-paste code, so it is only possible with an amount; the message
 *    is the order's own text and the image its header.
 *  - **Anywhere else, or when the native send is refused** — the same
 *    information as text, the key alone in its last bubble so a long press
 *    copies exactly the key. Which is what these flows sent before this node
 *    existed.
 *
 * The frontend mirrors these names, limits and the text rendering in
 * `lib/pixKeyNodes.ts`.
 */
final class PixKeyNodes
{
    /** Directory key types, in the builder's order. */
    public const KEY_TYPES = ['cpf', 'cnpj', 'phone', 'email', 'evp'];

    /** WhatsApp Official's limit on an order's body text. */
    public const MAX_MESSAGE_LENGTH = 1024;

    public const MAX_MERCHANT_LENGTH = 100;

    public const MAX_DESCRIPTION_LENGTH = 60;

    /**
     * The name on the card when the author left it blank. API Way refuses a
     * card without one, and "Pix" is what the customer reads it as anyway —
     * the bank shows the key owner's real name before they confirm.
     */
    public const DEFAULT_MERCHANT = 'Pix';

    /** An order cannot go out without a body; this is it when the node has no message. */
    public const DEFAULT_ORDER_BODY = 'Pagamento via Pix';

    /** The single line of the order WhatsApp Official shows. */
    public const DEFAULT_DESCRIPTION = 'Pagamento';

    /** How the key type reads to the customer. Pix is Brazilian, so is the copy. */
    private const TYPE_LABELS = [
        'cpf' => 'CPF',
        'cnpj' => 'CNPJ',
        'phone' => 'Celular',
        'email' => 'E-mail',
        'evp' => 'Chave aleatória',
    ];

    /**
     * What a new node holds.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'key_type' => 'cpf',
            'key' => '', // supports {{variable}}
            'merchant_name' => '', // who receives; blank = "Pix"
            'amount' => '', // "49,90" or {{variable}}; required for WhatsApp Official
            'message' => '', // sent with the key; supports {{variable}}
            'image_url' => '', // WhatsApp Official only: the order's header picture
            'description' => '', // WhatsApp Official only: the order's line item
        ];
    }

    public static function keyType(array $data): string
    {
        $type = $data['key_type'] ?? null;

        return in_array($type, self::KEY_TYPES, true) ? $type : 'cpf';
    }

    /**
     * The key in the spelling the Banco Central's directory uses, or null when
     * what was typed cannot be a key of that type — a node with a key that
     * cannot exist must not send it, because the customer would copy it, fail
     * at the bank, and blame the business.
     *
     * Length and shape only; check digits are the builder's warning to give,
     * not a reason for the flow to fall silent.
     */
    public static function normalizeKey(string $type, string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        return match ($type) {
            'cpf' => strlen($digits = self::digits($raw)) === 11 ? $digits : null,
            // Alphanumeric CNPJs are being issued from 2026; the last two
            // characters stay digits.
            'cnpj' => preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $cnpj = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $raw))) === 1 ? $cnpj : null,
            'phone' => self::normalizePhone($raw),
            'email' => self::normalizeEmail($raw),
            'evp' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw) === 1 ? strtolower($raw) : null,
            default => null,
        };
    }

    /**
     * Everything one send needs, already interpolated and checked — or null
     * when there is no usable key, in which case the node sends nothing.
     *
     * @param  array<string, mixed>  $data  interpolated node data
     * @return array{key_type: string, key: string, merchant_name: string, amount_cents: int|null, message: string, image_url: string|null, description: string}|null
     */
    public static function resolve(array $data): ?array
    {
        $type = self::keyType($data);
        $key = self::normalizeKey($type, (string) ($data['key'] ?? ''));

        if ($key === null) {
            return null;
        }

        $amount = PaymentNodes::parseAmount((string) ($data['amount'] ?? ''));
        $image = trim((string) ($data['image_url'] ?? ''));

        return [
            'key_type' => $type,
            'key' => $key,
            'merchant_name' => mb_substr(trim((string) ($data['merchant_name'] ?? '')), 0, self::MAX_MERCHANT_LENGTH),
            'amount_cents' => $amount !== null && $amount > 0 ? $amount : null,
            'message' => mb_substr(trim((string) ($data['message'] ?? '')), 0, self::MAX_MESSAGE_LENGTH),
            // A picture the Cloud API will fetch itself: anything but a public
            // https address is dropped rather than failing the whole order.
            'image_url' => filter_var($image, FILTER_VALIDATE_URL) && str_starts_with(strtolower($image), 'https://') ? $image : null,
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, self::MAX_DESCRIPTION_LENGTH),
        ];
    }

    /**
     * Whether the channel can draw this key as a Pix card. False is not a
     * failure: the node sends the text version instead.
     *
     * @param  array<string, mixed>  $pix  a resolve() result
     */
    public static function nativeOn(Channel $channel, array $pix): bool
    {
        if (! $channel->supportsPixKey()) {
            return false;
        }

        // Meta's Pix lives inside an order, and an order has a total.
        return $channel !== Channel::WhatsappOfficial || $pix['amount_cents'] !== null;
    }

    public static function merchantName(array $pix): string
    {
        return $pix['merchant_name'] !== '' ? $pix['merchant_name'] : self::DEFAULT_MERCHANT;
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? strtoupper($type);
    }

    /**
     * The one-line description of the key — the stored body of the card, and
     * what a conversation preview, a search and the AI's transcript read.
     */
    public static function summary(array $pix): string
    {
        $line = 'Chave Pix ('.self::typeLabel($pix['key_type']).'): '.$pix['key'];

        return $pix['amount_cents'] !== null
            ? $line.' · '.Money::format($pix['amount_cents'], 'BRL')
            : $line;
    }

    /**
     * What a sent card keeps in `messages.meta.pix`: what the customer saw,
     * so the panel's bubble can draw the same card. Served by MessageResource.
     *
     * @param  array<string, mixed>  $extra  channel-specific facts (the order's code)
     * @return array<string, mixed>
     */
    public static function storedMeta(array $pix, array $extra = []): array
    {
        return array_merge([
            'key_type' => $pix['key_type'],
            'key' => $pix['key'],
            'merchant_name' => self::merchantName($pix),
            'amount_cents' => $pix['amount_cents'],
            'currency' => 'BRL',
        ], $extra);
    }

    /**
     * The text rendering, one string per bubble: the message, the details, and
     * the key alone last — a long press on it copies exactly the key.
     *
     * ⚠️ Mirrored in `lib/pixKeyNodes.ts` (`pixKeyTextBubbles`); the builder
     * shows the author this, so the two must not drift.
     *
     * @return list<string>
     */
    public static function textBubbles(array $pix): array
    {
        $details = ['Chave Pix ('.self::typeLabel($pix['key_type']).'): '.$pix['key']];

        if ($pix['merchant_name'] !== '') {
            $details[] = 'Nome: '.$pix['merchant_name'];
        }

        if ($pix['amount_cents'] !== null) {
            $details[] = 'Valor: '.Money::format($pix['amount_cents'], 'BRL');
        }

        return array_values(array_filter([
            $pix['message'],
            implode("\n", $details),
            $pix['key'],
        ], fn (string $bubble) => $bubble !== ''));
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    /**
     * "+55" + area code + number. A number typed without the country code is
     * taken to be Brazilian — a Pix phone key always is.
     */
    private static function normalizePhone(string $raw): ?string
    {
        $digits = self::digits($raw);

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55'.$digits;
        }

        return preg_match('/^55\d{10,11}$/', $digits) === 1 ? '+'.$digits : null;
    }

    private static function normalizeEmail(string $raw): ?string
    {
        $email = strtolower($raw);

        // 77: the room the key has inside the code's account field.
        return strlen($email) <= 77 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
