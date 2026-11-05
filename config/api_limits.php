<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard API, per signed-in person
    |--------------------------------------------------------------------------
    |
    | Every authenticated dashboard route shares one budget per user, whatever
    | is spending it: our own SPA, a second tab, or a script running inside the
    | page with the same session. Before this the main API group had no ceiling
    | at all, and on 5 Nov 2026 one session was firing ~50 requests in a single
    | second every five minutes at a server with ten PHP workers.
    |
    | The SPA stays well under both numbers (a cold boot is about 15 requests,
    | opening a thread about 5) and retries a `rate_limited` 429 after
    | Retry-After, so reaching the ceiling slows a client down instead of
    | breaking it. 0 switches a limit off.
    |
    */

    'dashboard' => [
        'per_second' => (int) env('DASHBOARD_API_PER_SECOND', 20),
        'per_minute' => (int) env('DASHBOARD_API_PER_MINUTE', 600),
    ],

];
