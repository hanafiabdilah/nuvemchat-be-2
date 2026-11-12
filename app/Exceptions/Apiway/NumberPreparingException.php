<?php

namespace App\Exceptions\Apiway;

use App\Exceptions\ConnectionException;

/**
 * Connecting a "WhatsApp via API Way" connection is waiting for ProxyBR to
 * finish making the instance behind it.
 *
 * Not a failure: the connect endpoint answers 202 with the code
 * `number_preparing`, and the wizard keeps the spinner up and asks again — the
 * `apiway-updated` broadcast usually says when.
 */
class NumberPreparingException extends ConnectionException
{
    public const CODE = 'number_preparing';

    public function __construct()
    {
        parent::__construct('Estamos preparando o seu número de WhatsApp. Isso leva alguns segundos.', 202);
    }
}
