<?php

namespace App\Jobs;

use App\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendOtpSms implements ShouldQueue
{
    use Queueable;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 15];

    public function __construct(
        public string $phone,
        public string $code
    ) {}

    public function handle(SmsSender $smsSender): void
    {
        $smsSender->send(
            $this->phone,
            "کد تأیید گیم‌ورلد: {$this->code}\nاین کد تا ۲ دقیقه معتبر است."
        );
    }

    /**
     * The OTP expires after two minutes, so retrying later than that is pointless.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(2);
    }
}
