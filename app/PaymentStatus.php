<?php

namespace App;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending, self::Unpaid => 'در انتظار پرداخت',
            self::Paid => 'پرداخت شده',
            self::Partial => 'پرداخت ناقص',
            self::Failed => 'ناموفق',
            self::Refunded => 'مسترد شده',
        };
    }
}
