<?php

namespace App\Support;

/**
 * Brazilian mobiles exist in two spellings on WhatsApp: with the ninth digit
 * (55 11 9 8765-4321) and, for numbers that predate it, without — and the id
 * WhatsApp reports is whichever it has on file. A number typed by a person (or
 * sent by another system) always has the modern spelling, so matching only that
 * would open a second contact next to the one the inbox already shows.
 */
final class PhoneVariants
{
    /**
     * Every spelling the same number may be stored under, the given one first.
     * Digits only, country code included.
     *
     * @return list<string>
     */
    public static function of(string $phone): array
    {
        $variants = [$phone];

        if (str_starts_with($phone, '55')) {
            if (strlen($phone) === 13 && $phone[4] === '9') {
                $variants[] = substr($phone, 0, 4).substr($phone, 5);
            } elseif (strlen($phone) === 12 && in_array($phone[4], ['6', '7', '8', '9'], true)) {
                $variants[] = substr($phone, 0, 4).'9'.substr($phone, 4);
            }
        }

        return $variants;
    }
}
