<?php

namespace App\Enums\Crm;

/**
 * Where the relationship with a customer stands. Set by people today; won
 * deals move it to Customer once deals exist (CRM phase 2).
 */
enum CustomerStatus: string
{
    /** Wrote in, nothing known yet — every customer a conversation creates. */
    case New = 'new';

    /** Interested: being worked on. */
    case Prospect = 'prospect';

    /** Has bought. */
    case Customer = 'customer';

    /** Gone quiet, or asked not to be pursued. */
    case Inactive = 'inactive';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
