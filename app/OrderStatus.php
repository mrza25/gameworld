<?php

namespace App;

enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Pending = 'pending';

    case Processing = 'processing';

    case Completed = 'completed';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'در انتظار تأیید پرداخت',
            self::Pending => 'در حال بررسی',
            self::Processing => 'در حال پردازش',
            self::Completed => 'تکمیل شده',
            self::Cancelled => 'لغو شده',
        };
    }
}
