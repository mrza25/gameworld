<?php

namespace App\Notifications;

use App\Models\Order;

class CardPaymentReviewed extends UserNotification
{
    public function __construct(Order $order, bool $approved, ?string $reason = null)
    {
        $this->compose(
            $approved ? 'payment_approved' : 'payment_rejected',
            $approved ? "پرداخت سفارش #{$order->id} تأیید شد" : "رسید سفارش #{$order->id} رد شد",
            $approved
                ? 'سفارش شما وارد روند نصب شد.'
                : "دلیل: {$reason} لطفاً رسید درست را دوباره ارسال کنید.",
            "/my/orders/{$order->id}",
            [
                'order_id' => $order->id,
                'amount' => (float) $order->total,
                'reason' => $approved ? null : $reason,
            ]
        );
    }
}
