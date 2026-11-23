<?php

namespace App\Enums\Incident;

/** The two things an incident says: that something is wrong, and that it is fixed. */
enum DeliveryKind: string
{
    case Notice = 'notice';
    case Resolution = 'resolution';
}
