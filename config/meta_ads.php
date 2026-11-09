<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meta Ads metrics (ad spend, cost per lead, ROAS)
    |--------------------------------------------------------------------------
    |
    | Workspaces connect their ad accounts through Pingly's own Meta app, which
    | needs `ads_read` granted by Meta's app review (Advanced Access).
    |
    | Off: the dashboard shows the Meta Ads card as "coming soon", the API
    | refuses with 403 `feature_unavailable`, nothing is synced and no ads
    | summary goes out. Nothing stored is deleted, so switching it back on
    | brings every connected account back as it was — no deploy, only
    | `up -d --force-recreate` to reread .env.
    |
    | On by default while the app review video is recorded (Nov 2026).
    */

    'enabled' => (bool) env('META_ADS_ENABLED', true),
];
