<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default market
    |--------------------------------------------------------------------------
    |
    | The market a workspace joins when the domain it signed up on is not
    | registered to any market — the platform domain, localhost, the console.
    | Every workspace that existed before markets was backfilled to Brazil,
    | and this must name a row in the `markets` table.
    |
    */

    'default' => env('DEFAULT_MARKET', 'BR'),

];
