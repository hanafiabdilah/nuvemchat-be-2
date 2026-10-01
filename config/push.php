<?php

/*
 * Push notifications to the mobile app (Firebase Cloud Messaging).
 * Credentials are not here: the service account is uploaded in the Back Office
 * (Integrations → Firebase) and read through App\Services\Push\FirebaseConfig.
 * Runbook: docs/mobile-push.md
 */
return [
    // Kill switch. Off = nothing is queued, registrations still work.
    'enabled' => (bool) env('PUSH_ENABLED', true),

    // Sends go through the queue so a slow FCM never sits inside a webhook.
    'queue' => env('PUSH_QUEUE', 'default'),

    // Phones that have not re-registered in this many days are dropped by
    // `push:prune-devices`: the app re-registers on every launch, so a row this
    // old belongs to a phone that no longer opens it.
    'stale_after_days' => (int) env('PUSH_STALE_AFTER_DAYS', 60),
];
