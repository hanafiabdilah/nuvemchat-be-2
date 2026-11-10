<?php

/*
|--------------------------------------------------------------------------
| Voice calls answered from the dashboard (WhatsApp Cloud API Calling)
|--------------------------------------------------------------------------
|
| The agent's browser is the call's media endpoint (WebRTC); the backend only
| carries the signalling between Meta and that browser. Runbook:
| docs/whatsapp-calling.md
|
*/

return [
    // Kill switch. Off = calls are recorded in the thread exactly as before
    // (CallLog) and nobody is rung; Meta then times them out as unanswered.
    'enabled' => (bool) env('CALLS_ENABLED', true),

    // STUN servers handed to the answering browser. Meta's offer already
    // carries its relay's public candidates, so a browser behind NAT normally
    // connects without one; a server-reflexive candidate is cheap insurance for
    // the networks where it does not. Comma-separated; empty = none.
    'ice_servers' => array_values(array_filter(array_map('trim', explode(',', (string) env('CALLS_STUN_URLS', 'stun:stun.l.google.com:19302'))))),

    // How often the answering browser reports in during a call, and how long
    // without a report before the call is ended for it (closed tab, crashed
    // app, dead laptop). The second has to stay well above the first.
    'heartbeat_seconds' => (int) env('CALLS_HEARTBEAT_SECONDS', 10),
    'lost_after_seconds' => (int) env('CALLS_LOST_AFTER_SECONDS', 45),

    // Meta gives the business 30–60 s to answer. Past these, a call our
    // webhook never heard the end of is closed by `calls:sweep`.
    'ringing_timeout_seconds' => (int) env('CALLS_RINGING_TIMEOUT_SECONDS', 90),
    'connecting_timeout_seconds' => (int) env('CALLS_CONNECTING_TIMEOUT_SECONDS', 60),

    // A ceiling on any one call, against a heartbeat that keeps beating from a
    // tab nobody is in front of.
    'max_duration_minutes' => (int) env('CALLS_MAX_DURATION_MINUTES', 240),

    // The ring to a phone has to beat Meta's timeout, so it rides the fast
    // presence worker rather than the default queue, where an AI turn can sit
    // in front of it for a minute.
    'push_queue' => env('CALLS_PUSH_QUEUE', env('AI_PRESENCE_QUEUE', 'default')),
];
