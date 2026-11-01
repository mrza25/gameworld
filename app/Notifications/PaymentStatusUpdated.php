<?php

namespace App\Notifications;

use App\Models\Order;
use App\PaymentStatus;

class PaymentStatusUpdated extends UserNotification
{
    public function __construct(Order $order)
    {
        $label = match ($order->payment_status) {
            PaymentStatus::Paid => 'پرداخت شده',
            PaymentStatus::Pending, PaymentStatus::Unpaid => 'در انتظار پرداخت',
            PaymentStatus::Partial => 'پرداخت ناقص',
            PaymentStatus::Failed => 'ناموفق',
            PaymentStatus::Refunded => 'مسترد شده',
        };

        $this->compose(
            'payment_status',
            "وضعیت پرداخت سفارش #{$order->id}",
            "وضعیت پرداخت سفارش شما به «{$label}» تغییر کرد.",
            "/my/orders/{$order->id}",
            [
                'order_id' => $order->id,
                'payment_status' => $order->payment_status->value,
            ]
        );
    }
}
