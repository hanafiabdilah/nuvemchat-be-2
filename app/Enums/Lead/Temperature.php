<?php

namespace App\Enums\Lead;

/**
 * How warm the lead is — the second axis, and the one nobody drags.
 *
 * Stage says how far along the sale is; temperature says whether the person is
 * still engaged. They move independently, which is the whole point: a lead can
 * sit in Proposta (far along) and be cold (silent for a week), and that
 * combination — far along but going quiet — is precisely the follow-up list an
 * agent needs. Collapsed into one column, that lead has nowhere to stand.
 *
 * Only the band is stored on the card and shown in the UI. The raw score exists
 * so the thresholds can be retuned without re-deriving history.
 */
enum Temperature: string
{
    case Cold = 'cold';
    case Warm = 'warm';
    case Hot = 'hot';

    public const WARM_AT = 30;

    public const HOT_AT = 60;

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= self::HOT_AT => self::Hot,
            $score >= self::WARM_AT => self::Warm,
            default => self::Cold,
        };
    }
}
