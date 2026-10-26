<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Reverse proxies or load balancers in front of HelixDesk whose
    | X-Forwarded-* headers should be trusted, so URLs and redirects use the
    | public scheme and host. Comma-separated IP addresses or CIDR ranges,
    | or "*" to trust the proxy that connects directly to the application.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
