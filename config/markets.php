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

    /*
    |--------------------------------------------------------------------------
    | Languages a market can default to
    |--------------------------------------------------------------------------
    |
    | The dashboard's translations, by i18next code. Must follow
    | nuvemchat-fe-2/src/i18n/languages.ts: a market defaulting to a language
    | the dashboard has no translation file for shows its customers English.
    |
    */

    'locales' => [
        'pt_BR' => 'Português (Brasil)',
        'en' => 'English',
        'id' => 'Bahasa Indonesia',
    ],

];
