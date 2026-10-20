<?php

namespace App\Exceptions\Billing;

use App\Exceptions\UserFacingException;
use App\Services\Billing\CardDecline;
use Illuminate\Http\JsonResponse;

/**
 * The issuer refused a card the customer is paying with right now.
 *
 * Carries the *reason* (CardDecline) next to the sentence, so the dashboard can
 * say it in the reader's own language; the pt-BR sentence is what any other
 * client gets. The gateway's own code never leaves the server.
 */
class CardDeclinedException extends UserFacingException
{
    public const CODE = 'card_declined';

    public function __construct(public readonly string $reason = CardDecline::DECLINED)
    {
        parent::__construct(CardDecline::message($reason), 422, self::CODE);
    }

    /** @param  array<string, mixed>|null  $decline  A normalized payment's `decline` block. */
    public static function from(?array $decline): self
    {
        return new self(CardDecline::reason($decline));
    }

    public function toResponse(array $extra = []): JsonResponse
    {
        return parent::toResponse(['decline_reason' => $this->reason, ...$extra]);
    }
}
