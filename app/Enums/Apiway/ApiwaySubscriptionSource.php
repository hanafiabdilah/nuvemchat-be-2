<?php

namespace App\Enums\Apiway;

enum ApiwaySubscriptionSource: string
{
    /**
     * Free: made when a WhatsApp via API Way connection needed an instance, and
     * renewed for free while it serves one on a usable plan. The value predates
     * that (it meant "covered by the plan's included_instances quota") and is
     * kept because it is stored on every row.
     */
    case PlanIncluded = 'plan_included';
    /** Legacy: bought at catalog unit price before instances became free. Renewed free now, like the rest. */
    case Unit = 'unit';
}
