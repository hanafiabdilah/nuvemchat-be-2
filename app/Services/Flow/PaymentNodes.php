<?php

namespace App\Services\Flow;

/**
 * The Payment node's vocabulary.
 *
 * Mirrored on the frontend in `lib/paymentNodes.ts` — the branch names are
 * source handle ids and edge condition_values at once, the way a Response
 * node's are, so both sides have to spell them identically.
 *
 * What the node does: issue a charge in the workspace's own gateway, send the
 * customer the means to pay, and park the flow until the gateway answers. The
 * customer writing in the meantime does not move it — a payment node is waiting
 * for money, not for words.
 */
final class PaymentNodes
{
    /** The gateway confirmed the money. */
    public const BRANCH_PAID = 'paid';

    /** Expired unpaid, refused, or never created at all. */
    public const BRANCH_FAILED = 'failed';

    public const BRANCHES = [self::BRANCH_PAID, self::BRANCH_FAILED];

    /** A Pix charge: QR image, copy-and-paste code, and usually a link. */
    public const METHOD_PIX = 'pix';

    /** A hosted page that also takes cards and boleto (Mercado Pago only). */
    public const METHOD_CHECKOUT = 'checkout';

    public const METHODS = [self::METHOD_PIX, self::METHOD_CHECKOUT];

    public const DEFAULT_EXPIRES_MINUTES = 60;

    public const MIN_EXPIRES_MINUTES = 5;

    /** 30 days — as long as either gateway keeps a Pix open. */
    public const MAX_EXPIRES_MINUTES = 43200;

    /** R$ 1.000.000,00. Past that it is a typo in the amount, not a sale. */
    public const MAX_AMOUNT_CENTS = 100_000_000;

    /**
     * Variables the node writes into the flow state.
     *
     * Written the moment the charge exists — before the node's own message is
     * sent — so the message can say {{payment_amount}} and carry
     * {{payment_link}}, and later nodes (a pixel on the `paid` branch, a
     * condition on {{payment_status}}) can read them too.
     */
    public const VARIABLES = [
        'payment_status',
        'payment_amount',
        'payment_value',
        'payment_link',
        'payment_pix_code',
        'payment_id',
        'payment_error',
    ];

    /** Info-note codes, rendered in the reader's language by `lib/infoMessage.ts`. */
    public const INFO_CREATED = 'flow_payment_created';

    public const INFO_PAID = 'flow_payment_paid';

    public const INFO_EXPIRED = 'flow_payment_expired';

    public const INFO_FAILED = 'flow_payment_failed';

    public const INFO_PAID_LATE = 'flow_payment_paid_late';

    /** Where the flow state remembers which charge this node is waiting on. */
    public static function stateKey(int $nodeId): string
    {
        return "_payment_{$nodeId}";
    }

    /** @param  array<string, mixed>  $data */
    public static function method(array $data): string
    {
        $method = $data['method'] ?? null;

        return in_array($method, self::METHODS, true) ? $method : self::METHOD_PIX;
    }

    /** @param  array<string, mixed>  $data */
    public static function expiresInMinutes(array $data): int
    {
        $minutes = (int) ($data['expires_in_minutes'] ?? self::DEFAULT_EXPIRES_MINUTES);

        if ($minutes <= 0) {
            $minutes = self::DEFAULT_EXPIRES_MINUTES;
        }

        return max(self::MIN_EXPIRES_MINUTES, min($minutes, self::MAX_EXPIRES_MINUTES));
    }

    /** @param  array<string, mixed>  $data */
    public static function sendsQrCode(array $data): bool
    {
        return ($data['send_qr_code'] ?? true) !== false;
    }

    /** @param  array<string, mixed>  $data */
    public static function sendsCopyPaste(array $data): bool
    {
        return ($data['send_copy_paste'] ?? true) !== false;
    }

    /**
     * Whether the payment link goes out.
     *
     * Always for a checkout — the link *is* the charge, and a checkout the
     * customer never receives cannot be paid. Opt-in for a Pix, where the QR
     * and the code already are the means to pay.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sendsLink(array $data): bool
    {
        if (self::method($data) === self::METHOD_CHECKOUT) {
            return true;
        }

        return ($data['send_link'] ?? false) === true;
    }

    /**
     * An amount as a person types it, in cents — or null when it is not one.
     *
     * Brazilian first, because that is who types these: "49,90", "1.500",
     * "R$ 1.234,56". A dot followed by exactly three digits is a thousands
     * separator ("1.500" is fifteen hundred), anything else after a lone dot is
     * decimals ("49.9"). When both separators appear, the last one is the
     * decimal point. Zero and negatives are not amounts anyone can be charged.
     */
    public static function parseAmount(string $raw): ?int
    {
        $value = trim(str_ireplace(['R$', 'BRL'], '', $raw));
        $value = (string) preg_replace('/[\s\x{00A0}]+/u', '', $value);

        if ($value === '' || preg_match('/^\d[\d.,]*$/', $value) !== 1) {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $value = str_replace([$thousands, $decimal], ['', '.'], $value);
        } elseif ($lastComma !== false) {
            if (substr_count($value, ',') > 1) {
                return null;
            }
            $value = str_replace(',', '.', $value);
        } elseif ($lastDot !== false) {
            $decimals = strlen($value) - $lastDot - 1;
            if (substr_count($value, '.') > 1 || $decimals === 3) {
                $value = str_replace('.', '', $value);
            }
        }

        if (! is_numeric($value)) {
            return null;
        }

        $cents = (int) round(((float) $value) * 100);

        return $cents > 0 && $cents <= self::MAX_AMOUNT_CENTS ? $cents : null;
    }
}
