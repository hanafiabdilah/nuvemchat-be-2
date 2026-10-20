<?php

namespace App\Services\Billing;

/**
 * Why a card was refused, in words the cardholder can act on.
 *
 * Every gateway answers a refusal in its own vocabulary — Stripe's
 * `decline_code` (`insufficient_funds`), Mercado Pago's `status_detail`
 * (`cc_rejected_insufficient_amount`), the ISO 8583 response codes the
 * payment service passes through (`51`) — and before this every one of them
 * reached the customer as the same sentence: "the card was declined, check the
 * details or use another card". That sentence sends someone whose bank simply
 * had no funds that morning to retype a card number that was right.
 *
 * So the gateway code is folded into one of a handful of *reasons*, each with
 * one thing to do about it. The reason is ours (a stable code the dashboard
 * translates); the gateway's code stays in `meta.failure` for the Back Office.
 *
 * ⚠️ Lost, stolen, pick-up and fraud refusals deliberately read as a plain
 * decline. Telling the person at the screen "this card was reported stolen" is
 * telling a fraudster which card to stop trying — the card networks and Stripe
 * both say not to.
 */
final class CardDecline
{
    public const INSUFFICIENT_FUNDS = 'insufficient_funds';

    public const INCORRECT_CVC = 'incorrect_cvc';

    public const INCORRECT_NUMBER = 'incorrect_number';

    public const EXPIRED_CARD = 'expired_card';

    public const AUTHENTICATION_REQUIRED = 'authentication_required';

    public const CALL_ISSUER = 'call_issuer';

    public const CARD_NOT_SUPPORTED = 'card_not_supported';

    public const LIMIT_EXCEEDED = 'limit_exceeded';

    public const TRY_AGAIN_LATER = 'try_again_later';

    /** Anything else, and every reason not worth naming to the person paying. */
    public const DECLINED = 'card_declined';

    /** @var array<string, list<string>> reason => gateway codes, lower-cased */
    protected const CODES = [
        self::INSUFFICIENT_FUNDS => [
            'insufficient_funds', 'cc_rejected_insufficient_amount', '51',
        ],
        self::INCORRECT_CVC => [
            'incorrect_cvc', 'invalid_cvc', 'cc_rejected_bad_filled_security_code', '82', 'n7',
        ],
        self::INCORRECT_NUMBER => [
            'incorrect_number', 'invalid_number', 'cc_rejected_bad_filled_card_number', '14',
        ],
        self::EXPIRED_CARD => [
            'expired_card', 'invalid_expiry_month', 'invalid_expiry_year', 'invalid_expiry_date',
            'cc_rejected_bad_filled_date', '54',
        ],
        self::AUTHENTICATION_REQUIRED => [
            'authentication_required', 'cc_rejected_3ds_mandatory', 'cc_rejected_3ds_challenge', '1a',
        ],
        self::CALL_ISSUER => [
            'do_not_honor', 'call_issuer', 'transaction_not_allowed', 'not_permitted', 'service_not_allowed',
            'security_violation', 'no_action_taken', 'cc_rejected_call_for_authorize', 'cc_rejected_card_disabled',
            '01', '05', '57', '62',
        ],
        self::CARD_NOT_SUPPORTED => [
            'card_not_supported', 'currency_not_supported', 'cc_rejected_invalid_installments',
        ],
        self::LIMIT_EXCEEDED => [
            'card_velocity_exceeded', 'withdrawal_count_limit_exceeded', 'cc_rejected_max_attempts', '61', '65',
        ],
        self::TRY_AGAIN_LATER => [
            'processing_error', 'try_again_later', 'issuer_not_available', 'reenter_transaction',
            'cc_rejected_card_error', '19', '91', '96',
        ],
    ];

    /** pt-BR — the language of every other sentence the API sends about money. */
    protected const MESSAGES = [
        self::INSUFFICIENT_FUNDS => 'O cartão não tem saldo ou limite para este valor. Use outro cartão ou fale com o seu banco.',
        self::INCORRECT_CVC => 'O código de segurança (CVV) está incorreto. Confira e tente de novo.',
        self::INCORRECT_NUMBER => 'O número do cartão está incorreto. Confira e tente de novo.',
        self::EXPIRED_CARD => 'O cartão está vencido ou a validade está incorreta. Confira ou use outro cartão.',
        self::AUTHENTICATION_REQUIRED => 'O seu banco precisa confirmar este pagamento. Pague com o cartão nesta tela para confirmar.',
        self::CALL_ISSUER => 'O seu banco recusou este pagamento. Fale com o banco para liberar a compra ou use outro cartão.',
        self::CARD_NOT_SUPPORTED => 'Este cartão não aceita este tipo de compra. Use outro cartão.',
        self::LIMIT_EXCEEDED => 'O cartão atingiu o limite de compras ou de tentativas. Aguarde um pouco ou use outro cartão.',
        self::TRY_AGAIN_LATER => 'O banco não conseguiu processar o pagamento agora. Tente de novo em alguns minutos.',
        self::DECLINED => 'O cartão foi recusado. Confira os dados ou use outro cartão.',
    ];

    /**
     * @param  array<string, mixed>|null  $decline  A normalized payment's `decline` block: {category, code, advice?}.
     */
    public static function reason(?array $decline): string
    {
        $code = strtolower(trim((string) ($decline['code'] ?? '')));

        if ($code !== '') {
            foreach (self::CODES as $reason => $codes) {
                if (in_array($code, $codes, true)) {
                    return $reason;
                }
            }
        }

        // No code we know, but the issuer said how to proceed.
        return match ($decline['advice'] ?? null) {
            'try_again_later' => self::TRY_AGAIN_LATER,
            'confirm_card_data' => self::CALL_ISSUER,
            default => self::DECLINED,
        };
    }

    public static function message(string $reason): string
    {
        return self::MESSAGES[$reason] ?? self::MESSAGES[self::DECLINED];
    }

    /** @return list<string> */
    public static function reasons(): array
    {
        return array_keys(self::MESSAGES);
    }
}
