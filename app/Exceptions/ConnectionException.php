<?php

namespace App\Exceptions;

use App\Support\Errors\HasUserSafeMessage;
use Exception;

/**
 * A connection could not be set up, checked or used.
 *
 * ⚠️ Marked user-safe, which is a promise about every call site: the message
 * must be our own wording. Where a channel class has the provider's answer in
 * hand (the API Way core's QR/status refusals, an IMAP rejection), it runs it
 * through App\Support\Errors\UpstreamError first and throws the result.
 */
class ConnectionException extends Exception implements HasUserSafeMessage
{
    protected $httpStatusCode;

    public function __construct($message = "", $httpStatusCode = 500, $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getHttpStatusCode()
    {
        return $this->httpStatusCode;
    }
}
