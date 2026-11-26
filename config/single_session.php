<?php

return [

    /*
    |--------------------------------------------------------------------------
    | One computer and one phone per account
    |--------------------------------------------------------------------------
    |
    | When on, signing in to the dashboard ends the account's other dashboard
    | session of the same kind (App\Enums\Auth\SessionDevice): a sign-in at a
    | computer ends the computer that was signed in before, a sign-in on a
    | phone (or tablet) ends the phone. One of each stays signed in together.
    | The device that lost is told so in real time and returned to the login
    | screen with the reason on it.
    |
    | Only sign-ins count. An operator opening the workspace from the Back
    | Office (impersonation) neither ends the customer's session nor is ended
    | by it, and API keys and MCP connections are not sessions at all.
    |
    | Turning this off stops new sign-ins from ending anything; sessions that
    | were already ended stay ended.
    |
    */

    'enabled' => (bool) env('SINGLE_SESSION_ENABLED', true),

    /*
    | How long an ended session is remembered, so that a device which was
    | closed at the time still learns WHY its next request was refused rather
    | than being dropped on the login screen without a word. Past this, the old
    | token would have expired on its own anyway (sanctum.expiration).
    */

    'remember_days' => (int) env('SINGLE_SESSION_REMEMBER_DAYS', 8),

];
