<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal from the public API that is not about one field.
 *
 * Field problems stay ValidationException (422 with `errors`). This is for the
 * rest — "this workspace has two WhatsApp numbers, pick one", "WhatsApp
 * Official cannot open with free text" — where the caller is a program, so the
 * stable `code` is what it branches on and the sentence is for whoever reads
 * the log.
 */
class PublicApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  merged into the response body
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->extra), $this->status);
    }
}
