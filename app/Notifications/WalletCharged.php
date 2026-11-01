<?php

namespace App\Notifications;

use App\Models\Payment;

class WalletCharged extends UserNotification
{
    public function __construct(Payment $payment, float $balance)
    {
        $this->compose(
            'wallet_charged',
            'کیف پول شارژ شد',
            'کیف پول شما '.self::toman($payment->amount).' شارژ شد. موجودی فعلی: '.self::toman($balance).'.',
            '/my/wallet',
            [
                'payment_id' => $payment->id,
                'amount' => (float) $payment->amount,
                'balance' => $balance,
            ]
        );
    }
}
