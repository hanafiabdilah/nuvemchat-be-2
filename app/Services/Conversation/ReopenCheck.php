<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use Illuminate\Support\Carbon;

/**
 * The answer to "may this closed thread be opened again, and until when".
 *
 * A value object rather than a bare bool because the refusal is the useful
 * half: the button that cannot be pressed has to say *why* — the connection
 * never turned the feature on, the window ran out, somebody already has this
 * contact in another thread — and each of those is a different sentence in the
 * dashboard and a different HTTP status at the endpoint.
 */
class ReopenCheck
{
    public function __construct(
        public readonly bool $allowed,
        /** Stable code the SPA words for the reader; null when allowed. */
        public readonly ?string $reason = null,
        /** The moment the window closes — drawn as a countdown, and null when there is no window at all. */
        public readonly ?Carbon $deadline = null,
        /** The thread that is already open for this contact, when that is what blocks. */
        public readonly ?Conversation $openThread = null,
    ) {}

    public static function allow(?Carbon $deadline): self
    {
        return new self(true, null, $deadline);
    }

    public static function refuse(string $reason, ?Carbon $deadline = null, ?Conversation $openThread = null): self
    {
        return new self(false, $reason, $deadline, $openThread);
    }
}
