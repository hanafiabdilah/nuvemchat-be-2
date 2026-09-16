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
    | The platform's own business day
    |--------------------------------------------------------------------------
    |
    | When the daily passes run: renewal charges, due reminders, reconciliation,
    | the stale-lead sweep, log pruning. One zone for the whole platform, and
    | deliberately NOT one per market.
    |
    | Every daily pass here is a deadline pass with a window measured in days
    | (D-7 warn, D-3 charge), so the hour inside that window changes nothing
    | about whether the right thing happens — only about what time a reminder
    | lands. Making it per-market would mean either a schedule entry per market,
    | which cannot work when markets are created at runtime from the Back
    | Office, or every command running hourly and filtering on each tenant's
    | local hour: five commands rewritten to move a notification by a few hours.
    |
    | What was actually wrong was never the hour — it was that the dates inside
    | those notifications were UTC. That is fixed at Tenant::formatDate().
    |
    | The known cost of keeping it global: an Indonesian owner gets the billing
    | reminder around 19:00 local, and the stale-lead sweep runs mid-afternoon
    | there rather than overnight. Worth revisiting when a market outside the
    | Americas has enough workspaces for either to be noticed.
    |
    */

    'scheduler_timezone' => env('SCHEDULER_TIMEZONE', 'America/Sao_Paulo'),

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
    | The platform's own language
    |--------------------------------------------------------------------------
    |
    | What the platform falls back to when nobody has said otherwise: a person
    | with no chosen language whose workspace has no market, a notification
    | whose template was never translated, an admin override written before
    | languages existed.
    |
    | Deliberately not `config('app.locale')` — that one is 'en' and governs
    | Laravel's own validation strings, which is a different question from what
    | language this business writes to its customers in.
    |
    */

    'default_locale' => env('DEFAULT_LOCALE', 'pt_BR'),

    /*
    |--------------------------------------------------------------------------
    | How a date is written, per language
    |--------------------------------------------------------------------------
    |
    | A due date in a WhatsApp message is read by a person, and 05/08 is the
    | fifth of August to a Brazilian and the eighth of May to an American. The
    | pattern therefore belongs to the reader's language, not to the server.
    |
    | Indonesian writes dates the same way Portuguese does, which is why this
    | table looked unnecessary until a third language was possible.
    |
    */

    'date_formats' => [
        'pt_BR' => ['date' => 'd/m/Y', 'datetime' => 'd/m/Y H:i'],
        'id' => ['date' => 'd/m/Y', 'datetime' => 'd/m/Y H:i'],
        'en' => ['date' => 'm/d/Y', 'datetime' => 'm/d/Y g:i A'],
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

    /*
    |--------------------------------------------------------------------------
    | Billing rails that exist per country
    |--------------------------------------------------------------------------
    |
    | Which ways the *platform* can be paid in a country. This is geography, not
    | commerce: Pix is a Brazilian instant-payment rail operated by the Banco
    | Central, and no amount of gateway configuration makes it exist in Jakarta.
    |
    | ⚠️ Do not confuse this with `market_prices.{card,pix}_enabled`. Those are a
    | decision — "this plan takes Pix here" — and a decision is only meaningful
    | where the rail exists. Until Sep 2026 nothing held the fact, so the Back
    | Office drew a Pix checkbox for every country and the Fase 3 backfill copied
    | `pix_enabled = true` onto every market price row, Indonesia included.
    |
    | Kept in config rather than a `markets` column on purpose: an admin toggling
    | "Indonesia has Pix" would be recording something untrue, and the only thing
    | it could buy them is a charge that fails in front of a customer. Countries
    | genuinely gaining a rail is a release-sized event, not a form field.
    |
    | A country with no entry falls back to `default_billing_methods`: cards,
    | which cross borders. Naming a local rail we have not verified would be the
    | same mistake in the other direction.
    |
    */

    'billing_methods' => [
        'BR' => ['card', 'pix'],
        'ID' => ['card'],
    ],

    'default_billing_methods' => ['card'],

];
