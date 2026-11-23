<?php

namespace App\Enums\Incident;

/** What put the row there. */
enum DeliverySource: string
{
    /** The customer wrote while the incident was on. */
    case Reactive = 'reactive';

    /** The customer had written shortly before it was switched on. */
    case Retro = 'retro';

    /** Somebody sent it by hand from the incident page. */
    case Manual = 'manual';

    /** The "it is fixed" message sent when the incident was resolved. */
    case Resolution = 'resolution';
}
