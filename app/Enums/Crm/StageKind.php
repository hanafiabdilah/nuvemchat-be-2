<?php

namespace App\Enums\Crm;

/**
 * What reaching a stage means. A deal's status is its stage's kind: moving it
 * into a Won stage wins it, into a Lost stage loses it.
 */
enum StageKind: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $kind) => $kind->value, self::cases());
    }
}
