<?php

namespace App\Enums\Incident;

/**
 * Who the notice is for.
 *
 * `all` answers the first message of everyone who writes in during the
 * incident, whatever it says — a total outage. `keywords` answers only when
 * that first message mentions it — a partial one, where the customer asking
 * about an invoice has nothing to do with the server that went down.
 */
enum MatchMode: string
{
    case All = 'all';
    case Keywords = 'keywords';
}
