<?php

namespace App\Enums\Lead;

/**
 * Denormalised from the lead's stage kind so it can be indexed and filtered
 * without joining stages on every board query — and so the one-open-lead-per
 * -contact invariant has a plain column to key its generated index on.
 */
enum LeadStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
