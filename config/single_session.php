<?php

return [

    /*
    |--------------------------------------------------------------------------
    | One sign-in per account
    |--------------------------------------------------------------------------
    |
    | When on, signing in to the dashboard ends every other dashboard session
    | of the same account: the device that was signed in before is told so in
    | real time and returned to the login screen with the reason on it.
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
