<?php

namespace App\Notifications;

use App\Models\Payment;

/**
 * Tells admins that a card-to-card receipt is waiting for review.
 */
class ReceiptSubmitted extends UserNotification
{
    public function __construct(Payment $payment)
    {
        $isWalletCharge = $payment->isWalletCharge();

        $this->compose(
            'receipt_submitted',
            $isWalletCharge ? 'رسید جدید برای شارژ کیف پول' : "رسید جدید برای سفارش #{$payment->order_id}",
            'مشتری رسید پرداخت '.self::toman($payment->amount).' را ارسال کرده است. بعد از دیدن واریز در حساب، آن را تأیید کنید.',
            $isWalletCharge ? '/admin/wallet-charges' : "/admin/orders/{$payment->order_id}",
            [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'amount' => (float) $payment->amount,
                'purpose' => $isWalletCharge ? 'wallet_charge' : 'order',
            ]
        );
    }
}
