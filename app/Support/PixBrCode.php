<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A static Pix "copia e cola" code (BR Code, EMV-QRCPS) built from a key.
 *
 * The gateways issue *dynamic* codes, one per charge, and the payment node
 * takes those as they come. A Pix key typed into a flow has no gateway behind
 * it, so the code is assembled here instead — the format the Banco Central
 * publishes, nothing more: a few TLV fields and a CRC16 at the end. Any bank
 * app reads it, and the amount travels with it.
 *
 * Needed for one reason: WhatsApp Official sends a Pix key inside an order
 * (`order_details`, see WhatsappOfficialHandler::handleSendPixKey), and Meta
 * requires the copy-and-paste code alongside the key itself.
 *
 * ⚠️ The name and the city are informational. A static code is paid to
 * whoever owns the key in the Banco Central's directory, and the bank shows
 * *that* name before the customer confirms — so a wrong name here cannot send
 * money anywhere else. It is still kept short and ASCII: the format caps the
 * name at 25 bytes and the city at 15, and some bank apps refuse accents.
 */
final class PixBrCode
{
    public const MAX_NAME_LENGTH = 25;

    public const MAX_CITY_LENGTH = 15;

    /**
     * What the city field holds when nobody said. The field is mandatory and
     * no bank validates it against anything.
     */
    public const DEFAULT_CITY = 'BRASIL';

    /**
     * The transaction id of a static code. "***" is the Banco Central's own
     * value for "no id" — a static code is reusable by design.
     */
    private const NO_TXID = '***';

    /**
     * @param  string  $key  the key in the directory's own spelling — see
     *                       PixKeyNodes::normalizeKey()
     * @param  int|null  $amountCents  null leaves the amount for the customer to type
     */
    public static function build(string $key, string $name, ?int $amountCents = null, string $city = self::DEFAULT_CITY): string
    {
        $account = self::field('00', 'br.gov.bcb.pix').self::field('01', $key);

        $payload = self::field('00', '01')
            .self::field('26', $account)
            .self::field('52', '0000')
            .self::field('53', '986')
            .($amountCents !== null && $amountCents > 0
                ? self::field('54', number_format($amountCents / 100, 2, '.', ''))
                : '')
            .self::field('58', 'BR')
            .self::field('59', self::text($name, self::MAX_NAME_LENGTH, 'PIX'))
            .self::field('60', self::text($city, self::MAX_CITY_LENGTH, self::DEFAULT_CITY))
            .self::field('62', self::field('05', self::NO_TXID))
            .'6304';

        return $payload.self::crc16($payload);
    }

    /** One TLV field: two-digit id, two-digit byte length, value. */
    private static function field(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /**
     * Plain ASCII, nothing a bank app would refuse, within the field's limit.
     * Falls back to $fallback when nothing printable is left — the field
     * cannot be empty.
     */
    private static function text(string $value, int $max, string $fallback): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9 .\-]/', '', Str::ascii($value));
        $ascii = trim((string) preg_replace('/\s+/', ' ', $ascii));

        return $ascii === '' ? $fallback : rtrim(substr($ascii, 0, $max));
    }

    /** CRC16-CCITT (polynomial 0x1021, start 0xFFFF), as the format specifies. */
    private static function crc16(string $payload): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            $crc ^= ord($payload[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
