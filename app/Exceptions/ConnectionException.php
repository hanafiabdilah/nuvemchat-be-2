<?php

namespace App\Exceptions;

use Exception;

class ConnectionException extends Exception
{
    protected $httpStatusCode;

    public function __construct($message = "", $httpStatusCode = 500, $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getHttpStatusCode()
    {
        return $this->httpStatusCode;
    }
}
