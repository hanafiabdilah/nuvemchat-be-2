<?php

return [

    /*
    | Where an affiliate link sends the visitor once it has locked them to the
    | affiliator: the marketing site. Signing up from there still counts — the
    | lock is a cookie on the platform host, where signup happens.
    */
    'landing_url' => env('AFFILIATE_LANDING_URL', 'https://pingly.com.br'),

    /*
    | How long the lock lasts before signup. Browsers cap cookies at about 400
    | days, so that is "until they sign up" in practice.
    */
    'cookie_days' => (int) env('AFFILIATE_COOKIE_DAYS', 400),

    /*
    | Default days a commission waits before it can be paid out, so a refund or
    | chargeback can still reverse it. The live value is a setting edited in
    | the Back Office (AffiliateSettings).
    */
    'hold_days' => (int) env('AFFILIATE_HOLD_DAYS', 30),

];
