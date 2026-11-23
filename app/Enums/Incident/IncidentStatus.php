<?php

namespace App\Enums\Incident;

/**
 * Where an incident is in its life.
 *
 * Only `active` answers anyone. `expired` and `resolved` are both endings and
 * kept apart on purpose: one is a person saying it is fixed, the other is the
 * clock running out on an incident nobody closed — which is the one worth
 * noticing afterwards.
 */
enum IncidentStatus: string
{
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Resolved = 'resolved';
    case Expired = 'expired';
    case Canceled = 'canceled';

    public function isOver(): bool
    {
        return in_array($this, [self::Resolved, self::Expired, self::Canceled], true);
    }
}
