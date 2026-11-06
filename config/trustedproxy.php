<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The proxies in front of the application, comma-separated. Hosts such as
    | Railway have no fixed proxy addresses, so use "*" there. Leave this empty
    | when nothing sits in front of the application, otherwise clients could
    | fake their IP address and get around the rate limits.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
