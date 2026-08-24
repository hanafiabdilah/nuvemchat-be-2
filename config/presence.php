<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent presence
    |--------------------------------------------------------------------------
    |
    | How long after its last heartbeat a dashboard still counts as staffed.
    | The SPA pings once a minute, so the window is deliberately wider than the
    | interval: a single dropped request must not read as someone walking away,
    | and a background tab whose timers the browser throttles is still an agent
    | sitting at their desk.
    |
    | Only "return to the last agent" reads this today. Widening it makes that
    | routing more eager (and more likely to hand a thread to an empty chair);
    | narrowing it makes it stricter.
    |
    */

    'online_seconds' => (int) env('PRESENCE_ONLINE_SECONDS', 150),

];
