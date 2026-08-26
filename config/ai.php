<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI agent turn delay (the "boom chat" window)
    |--------------------------------------------------------------------------
    |
    | How long an AIAgent node waits after the customer's last message before
    | it answers. Every message that lands inside the window pushes the wait
    | back, and when it finally elapses the whole burst is answered once, as a
    | single turn.
    |
    | People do not write one message per thought. "Oi" / "tenho uma dúvida" /
    | "sobre o pedido 123" is one question typed in three bursts, and without
    | this window it was three hub runs and three replies — the first two
    | answering a question the customer had not finished asking, all three
    | billed, and the customer left reading a bot talking over them.
    |
    | The cost is latency on the common case: a customer who really did send
    | one message waits this long for the first word back. Chat tolerates that
    | far better than it tolerates being interrupted, which is why the default
    | is a real pause and not a token one.
    |
    | 0 answers the instant a message arrives (the behaviour before this
    | existed). A single AIAgent node overrides it with `response_delay_seconds`
    | in its node data.
    |
    */

    'turn_delay_seconds' => (int) env('AI_TURN_DELAY_SECONDS', 4),

    /*
    | Ceiling for the per-node override. A flow builder typo must not be able
    | to park a live conversation for an hour.
    */

    'max_turn_delay_seconds' => (int) env('AI_MAX_TURN_DELAY_SECONDS', 300),

];
