<?php

namespace App\Notifications;

use App\Models\Payment;

class WalletChargeRejected extends UserNotification
{
    public function __construct(Payment $payment, string $reason)
    {
        $this->compose(
            'wallet_charge_rejected',
            'رسید شارژ کیف پول رد شد',
            "برای شارژ کیف پول درخواست جدیدی ثبت کنید. دلیل رد: {$reason}",
            '/my/wallet',
            [
                'payment_id' => $payment->id,
                'amount' => (float) $payment->amount,
                'reason' => $reason,
            ]
        );
    }
}
