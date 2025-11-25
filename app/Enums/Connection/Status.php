<?php

namespace App\Enums\Connection;

enum Status: string
{
    case Inactive = 'inactive';
    case Pending = 'pending';
    case Active = 'active';
}
