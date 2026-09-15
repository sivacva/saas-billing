<?php

return [

    'usage_events' => [

        /*
        |----------------------------------------------------------------------
        | Usage event rate limiting
        |----------------------------------------------------------------------
        |
        | Applied per API token (see the 'usage-events' limiter in
        | AppServiceProvider), not per IP - a merchant's backend can call
        | this endpoint from many source IPs behind the same token, and a
        | shared corporate egress IP shouldn't throttle unrelated tokens
        | against each other.
        |
        */

        'rate_limit' => [
            'max_attempts' => env('USAGE_EVENTS_RATE_LIMIT_MAX_ATTEMPTS', 120),
            'decay_minutes' => env('USAGE_EVENTS_RATE_LIMIT_DECAY_MINUTES', 1),
        ],

    ],

];
