<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Requests carry credentials, so outside local development only the real
    // frontend may call the API. The other hosts are for testing over a LAN.
    'allowed_origins' => array_values(array_filter([
        rtrim((string) env('FRONTEND_URL', ''), '/'),
        ...(env('APP_ENV') === 'local' ? [
            'http://127.0.0.1:3000',
            'http://localhost:3000',
            'http://10.100.0.2:3000',
            'http://192.168.1.149:3000',
            'http://gameworld.test',
        ] : []),
    ])),

    'allowed_origins_patterns' => env('APP_ENV') === 'local' ? [
        '#^https?://(192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+|localhost|127\.0\.0\.1)(:\d+)?$#',
    ] : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['*'],

    'max_age' => 86400,

    'supports_credentials' => true,

];
