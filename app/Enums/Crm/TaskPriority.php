<?php

namespace App\Enums\Crm;

enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $priority) => $priority->value, self::cases());
    }
}
