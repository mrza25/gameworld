<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'payment' => [
        // The sandbox gateway hands out free wallet credit, so production refuses it unless told otherwise.
        'allow_fake_gateway' => (bool) env('PAYMENT_ALLOW_FAKE_GATEWAY', false),
    ],

    'card_to_card' => [
        // The method stays off until a card number is set.
        'number' => env('CARD_TO_CARD_NUMBER'),
        'holder' => env('CARD_TO_CARD_HOLDER'),
        'bank' => env('CARD_TO_CARD_BANK'),
        'payment_hours' => (int) env('CARD_TO_CARD_PAYMENT_HOURS', 24),
    ],

    'otp' => [
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN', 60),
        'hourly_limit' => (int) env('OTP_HOURLY_LIMIT', 5),
    ],

    'rate_limits' => [
        'api_per_minute' => (int) env('API_RATE_LIMIT', 300),
        'login_per_minute' => (int) env('LOGIN_ATTEMPTS_PER_MINUTE', 5),
    ],

];
