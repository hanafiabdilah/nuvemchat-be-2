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

    /*
    |--------------------------------------------------------------------------
    | Tax identity accepted per country
    |--------------------------------------------------------------------------
    |
    | Every charge carries the payer's tax number: the acquirer refuses a Pix or
    | a boleto without a CPF, and the same is true of local rails elsewhere. The
    | shapes are per country, so a Brazilian rule applied to an Indonesian
    | customer is not a validation message — it is a workspace that can never
    | pay at all, which is exactly what happened before markets existed.
    |
    | `digits` are counted after everything that is not a digit is stripped, so
    | a customer may type the dots and dashes their country writes.
    |
    | A country with no entry falls back to `default`: a generic tax id, wide
    | enough to accept one and narrow enough to catch a phone number typed by
    | mistake. Better than refusing the sale while somebody adds a row here.
    |
    */

    'documents' => [
        'BR' => [
            ['code' => 'CPF', 'min' => 11, 'max' => 11],
            ['code' => 'CNPJ', 'min' => 14, 'max' => 14],
        ],
        'ID' => [
            // NPWP is 15 digits on older cards and 16 since it was aligned with
            // the NIK; both are in circulation, and a business may hold either.
            ['code' => 'NPWP', 'min' => 15, 'max' => 16],
            ['code' => 'NIK', 'min' => 16, 'max' => 16],
        ],
    ],

    'default_documents' => [
        ['code' => 'TAX_ID', 'min' => 5, 'max' => 20],
    ],

];
