<?php

namespace App\Exceptions\Billing;

use App\Exceptions\UserFacingException;

/**
 * A coupon refused at checkout. The `code` (`coupon_*`) is what the dashboard
 * translates; the sentence here is only the fallback for a client that does
 * not know the code yet.
 */
class CouponException extends UserFacingException
{
    private const MESSAGES = [
        'not_found' => 'Cupom não encontrado.',
        'inactive' => 'Este cupom não está mais ativo.',
        'not_started' => 'Este cupom ainda não começou a valer.',
        'expired' => 'Este cupom expirou.',
        'exhausted' => 'Este cupom já atingiu o limite de usos.',
        'already_used' => 'Você já usou este cupom.',
        'plan_only' => 'Este cupom vale apenas para planos.',
        'topup_only' => 'Este cupom vale apenas para recargas de saldo.',
        'market' => 'Este cupom não vale no seu país.',
        'plan' => 'Este cupom não vale para este plano.',
        'cycle' => 'Este cupom não vale para este ciclo de cobrança.',
        'not_charged_now' => 'Cupons valem apenas para um plano pago agora.',
        'busy' => 'Muitas pessoas estão usando este cupom agora. Tente novamente em instantes.',
        'too_many' => 'Muitas tentativas com cupons inexistentes. Aguarde alguns minutos e tente novamente.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES['not_found'], 422, "coupon_{$reason}");
    }

    /** @return list<string> */
    public static function reasons(): array
    {
        return array_keys(self::MESSAGES);
    }
}
