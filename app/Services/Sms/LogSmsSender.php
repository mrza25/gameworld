<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Development SMS driver: writes messages to the log instead of sending them.
 * Bind a real provider implementation of SmsSender for production.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        // Messages hold login codes, which must not end up in a live server's logs.
        if (app()->isProduction()) {
            Log::error('SMS not sent: no SMS provider is configured.');

            return;
        }

        Log::info("SMS to {$phone}: {$message}");
    }
}
