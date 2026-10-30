<?php

namespace App\Enums\Crm;

/** Where a lead stands, from first contact to a deal (or nowhere). */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Disqualified = 'disqualified';
    /** Became a deal (`converted_deal_id`); closed as a lead. */
    case Converted = 'converted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    /** Still being worked. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Qualified], true);
    }
}
