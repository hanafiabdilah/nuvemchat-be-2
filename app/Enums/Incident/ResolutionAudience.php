<?php

namespace App\Enums\Incident;

/**
 * Who hears that it is fixed. `all` is everyone who was told it was broken;
 * `open` leaves out the conversations an attendant has already closed.
 */
enum ResolutionAudience: string
{
    case All = 'all';
    case Open = 'open';
}
