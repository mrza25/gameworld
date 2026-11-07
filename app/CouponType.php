<?php

namespace App;

enum CouponType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'درصدی',
            self::Fixed => 'مبلغ ثابت',
        };
    }
}
