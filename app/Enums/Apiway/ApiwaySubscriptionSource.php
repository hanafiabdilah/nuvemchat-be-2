<?php

namespace App\Enums\Apiway;

enum ApiwaySubscriptionSource: string
{
    /** Granted by the tenant's plan (`included_instances` quota) — renewed free while the plan is usable. */
    case PlanIncluded = 'plan_included';
    /** Bought at catalog unit price — renewals billed to the tenant (Pix invoice or card preapproval). */
    case Unit = 'unit';
}
