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

];
