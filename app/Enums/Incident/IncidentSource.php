<?php

namespace App\Enums\Incident;

/** Who switched the incident on. */
enum IncidentSource: string
{
    case Manual = 'manual';
    case Api = 'api';
}
