<?php

namespace App\Enums\Lead;

/**
 * What a stage means to the business, independent of what it is called.
 *
 * Tenants rename stages freely and run the board in Portuguese, so no report
 * may ever key on the name. "Did this sale happen?" is answered here.
 */
enum StageKind: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    /** A stage that closes the lead — reaching it ends the attempt either way. */
    public function isTerminal(): bool
    {
        return $this !== self::Open;
    }

    public function toLeadStatus(): LeadStatus
    {
        return match ($this) {
            self::Open => LeadStatus::Open,
            self::Won => LeadStatus::Won,
            self::Lost => LeadStatus::Lost,
        };
    }
}
