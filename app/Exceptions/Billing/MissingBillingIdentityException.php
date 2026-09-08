<?php

namespace App\Exceptions\Billing;

use App\Exceptions\UserFacingException;

/**
 * The workspace has no CPF or CNPJ on file, so nothing can be charged.
 *
 * A Pix or a boleto without one is refused by the acquirer, so the payment
 * service demands it on every charge. This is thrown *before* that call, where
 * the sentence can still say what to do about it — the alternative is a
 * validation error naming `customer.document_number`, a field the person
 * reading it has never seen and cannot find.
 *
 * A `UserFacingException`, so the catch-all translators leave the wording
 * alone: this is one of the few billing failures the customer genuinely owns
 * and can fix in under a minute.
 */
class MissingBillingIdentityException extends UserFacingException
{
    public function __construct()
    {
        parent::__construct(
            'Informe o CPF ou CNPJ do responsável pela cobrança antes de continuar. '
            .'Você pode preenchê-lo em Cobrança → Dados de faturamento.',
            422,
        );
    }
}
