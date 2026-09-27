<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Run the flow engine on a queue
    |--------------------------------------------------------------------------
    |
    | A flow executes inside the request that delivered the customer's message.
    | A message node calls the channel API, an http_request node calls whatever
    | endpoint the flow author typed, and the webhook — and the PHP-FPM worker
    | serving it — waits for all of it. Enough inbound messages against a slow
    | flow and the pool is full, at which point the whole platform answers 502,
    | not just that workspace.
    |
    | Turning this on moves the turn to a queued job, so the webhook records the
    | message, broadcasts it, and returns.
    |
    | ⚠️ DEFAULT OFF, and that is not caution for its own sake: it is a real
    | change in behaviour, not an optimisation. Flow replies stop being
    | synchronous with the inbound message, and a platform whose queue workers
    | are down stops running flows entirely rather than degrading — today they
    | keep working. Read docs/flow-queue.md before switching it on, and switch
    | it on deliberately.
    |
    */

    'queue' => (bool) env('FLOW_QUEUE_ENABLED', false),

    /*
    | Which queue the turn goes to.
    |
    | `default` so that enabling the switch needs no ops step — the existing
    | worker picks it up — which is the same call config('queue.media') and
    | AI_PRESENCE_QUEUE make.
    |
    | ⚠️ On a busy platform, give it its own worker. A flow turn blocks its
    | worker for the whole of every channel call it makes, and `default` is
    | where RunAiAgentTurn also runs: leave them together under load and a slow
    | http_request node delays the AI's reply to somebody else's customer.
    */
    'queue_name' => env('FLOW_QUEUE', 'default'),

    /*
    | Hard ceiling on an http_request node, in seconds.
    |
    | Node data is validated at save (FlowBlueprint allows 1–120), but rows
    | written before that rule existed are not, and an imported flow carries
    | whatever the file said. This is what the engine actually honours.
    */
    'http_max_timeout' => (int) env('FLOW_HTTP_MAX_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Filling a flow's pauses: "digitando…" / "gravando áudio…"
    |--------------------------------------------------------------------------
    |
    | A Message node's pause between bubbles has always been silent. Its whole
    | stated purpose is to make a sequence read like somebody typing — and from
    | the customer's seat, a number that went quiet for twenty seconds and then
    | produced a voice note is indistinguishable from a number that is broken.
    | The indicator is what turns the pause into the thing it was set to be.
    |
    | On by default, and deliberately: there are no accidental pauses. Every one
    | of them is a number an author typed into a field labelled "wait before
    | sending", which is a request for exactly this. The switch below is the way
    | to take it back across every flow at once without editing any of them.
    |
    */

    'presence' => [

        'enabled' => (bool) env('FLOW_PRESENCE_ENABLED', true),

        /*
        | Longest the indicator is shown for, however long the pause is.
        |
        | It covers the *end* of the pause, not the start. Nobody records a
        | voice note for five minutes, and an indicator that ran for the first
        | half of a long wait and then stopped would read as the bot giving up.
        | Silence, then "gravando áudio…", then the message is the sequence a
        | person produces — so a pause longer than this is quiet until its last
        | stretch.
        |
        | For the common case this changes nothing: the Message node caps a
        | pause at 300s but they are written in single digits, and anything at
        | or under this number is covered end to end.
        */
        'max_seconds' => (int) env('FLOW_PRESENCE_MAX_SECONDS', 30),

        /*
        | Which queue carries the refresh beats.
        |
        | Defaults to the queue AI presence already uses, because it is the same
        | work and production already runs a worker for it (`queue-presence`) —
        | so this needs no ops step wherever that step has been taken, and falls
        | back to `default` where it has not. Its own env var exists for the day
        | the two need to be pulled apart.
        */
        'queue' => env('FLOW_PRESENCE_QUEUE', env('AI_PRESENCE_QUEUE', 'default')),

    ],

];
