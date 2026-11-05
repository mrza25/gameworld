<?php

namespace App\Notifications;

use App\Models\Order;
use App\Services\CardPaymentService;

class OrderAwaitingCardPayment extends UserNotification
{
    public function __construct(Order $order)
    {
        $this->compose(
            'order_awaiting_payment',
            "سفارش #{$order->id} ثبت شد",
            'مبلغ '.self::toman($order->total).' را کارت به کارت کنید و عکس رسید را تا '.CardPaymentService::paymentHours().' ساعت آینده ارسال کنید.',
            "/my/orders/{$order->id}",
            [
                'order_id' => $order->id,
                'amount' => (float) $order->total,
            ]
        );
    }
}
