<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Live conversation activity
    |--------------------------------------------------------------------------
    |
    | The realtime "what is happening in this thread right now" signals: which
    | flow node is executing, whether the AI is mid-turn, which agent is typing.
    |
    | Unlike the message events, these broadcast synchronously (ShouldBroadcastNow)
    | — an indicator that queues behind a worker arrives after the thing it was
    | announcing. That puts an HTTP call to Reverb inside the webhook request
    | that delivers a customer's message, once per flow node. It is a couple of
    | milliseconds each and the flow is short, but it is real work on a hot path
    | for a decoration, so it gets a switch that does not need a deploy.
    |
    | Turning this off silences the flow/AI indicators. Typing keeps working:
    | that one rides the same endpoint as the customer-facing indicator, which is
    | not a decoration on the flow's critical path.
    |
    */

    'activity_enabled' => (bool) env('LIVE_ACTIVITY_ENABLED', true),

    /*
    | How long a client keeps showing "X is typing" after the last heartbeat.
    | The composer re-announces every 4s, so this is a wide margin: one dropped
    | request must not blink the indicator off while somebody is still writing.
    */

    'typing_ttl_seconds' => (int) env('LIVE_TYPING_TTL_SECONDS', 8),

];
