<?php

namespace App\Services\Flow;

/**
 * What happened to one bubble of a Message node.
 *
 * Three answers, not two, because "it did not go out" splits into two very
 * different situations and the flow has to act differently on each: a channel
 * we could not reach at all is worth trying again in a few seconds, while a
 * channel that answered no is not, and retrying it only delays the moment
 * somebody finds out.
 *
 * `reason` is already copy written for a person — it comes out of
 * MessageService::guard(), which translated whatever the channel said. That is
 * what lets it go straight into the note left in the thread.
 */
final class BubbleDelivery
{
    private function __construct(
        public readonly bool $sent,
        public readonly bool $retriable,
        public readonly ?string $reason,
    ) {}

    public static function sent(): self
    {
        return new self(true, false, null);
    }

    /** Nothing reached the channel, so sending again cannot duplicate it. */
    public static function retriable(string $reason): self
    {
        return new self(false, true, $reason);
    }

    public static function failed(?string $reason): self
    {
        return new self(false, false, $reason);
    }
}
