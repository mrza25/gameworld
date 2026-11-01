<?php

namespace App\Notifications;

use App\Models\Payment;

class GatewayPaymentFailed extends UserNotification
{
    public function __construct(Payment $payment)
    {
        $isWalletCharge = $payment->isWalletCharge();
        $bankNote = 'اگر مبلغی از حساب بانکی شما کسر شده باشد، طی ۷۲ ساعت توسط بانک برگردانده می‌شود.';

        $this->compose(
            'payment_failed',
            $isWalletCharge ? 'شارژ کیف پول ناموفق بود' : 'پرداخت سفارش ناموفق بود',
            ($isWalletCharge
                ? 'شارژ کیف پول به مبلغ '.self::toman($payment->amount).' انجام نشد. '
                : 'پرداخت '.self::toman($payment->amount).' انجام نشد و سفارشی ثبت نشد. ').$bankNote,
            $isWalletCharge ? '/my/wallet' : '/my/orders',
            [
                'payment_id' => $payment->id,
                'amount' => (float) $payment->amount,
                'purpose' => $isWalletCharge ? 'wallet_charge' : 'order',
            ]
        );
    }
}
