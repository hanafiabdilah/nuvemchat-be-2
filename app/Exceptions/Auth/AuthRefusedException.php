<?php

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A sign-in, sign-up or verification step refused for a reason the screen has
 * to explain — a wrong code with attempts left, a code that is used up, an
 * account blocked for a while.
 *
 * Shaped like a validation error (`errors.{field}`) so every client that
 * already shows the first field error keeps working, and carries a stable
 * `code` beside it, because the screens word these themselves: the sentence
 * here is English, the people reading it mostly are not, and a block has to
 * say how long it lasts and who to call — which a translated sentence built
 * from `retry_after` and `support_email` can, and a fixed one cannot.
 */
class AuthRefusedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  merged into the response body
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly string $field,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * A block: how long it lasts, and who can help in the meantime.
     *
     * The support address travels with the refusal rather than living in the
     * app — it is the platform's, and it may differ per deployment.
     */
    public static function blocked(string $message, string $errorCode, string $field, int $seconds, bool $withSupport = true): self
    {
        return new self($message, $errorCode, $field, 429, array_filter([
            'retry_after' => max(1, $seconds),
            'support_email' => $withSupport ? (config('app.support_email') ?: null) : null,
        ], fn ($value) => $value !== null));
    }

    public function render(): JsonResponse
    {
        $response = response()->json(array_merge([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => [$this->field => [$this->getMessage()]],
        ], $this->extra), $this->status);

        if (isset($this->extra['retry_after'])) {
            $response->headers->set('Retry-After', (string) $this->extra['retry_after']);
        }

        return $response;
    }
}
