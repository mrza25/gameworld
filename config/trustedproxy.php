<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The proxies in front of the application, comma-separated, such as a load
    | balancer or CDN. Use "*" for one with no fixed addresses. Leave this empty
    | when nothing sits in front of the application, otherwise clients could
    | fake their IP address and get around the rate limits.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
