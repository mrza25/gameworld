<?php

namespace App\Notifications;

use App\Models\Order;

class OrderPlaced extends UserNotification
{
    public function __construct(Order $order)
    {
        $this->compose(
            'order_placed',
            "سفارش #{$order->id} ثبت شد",
            'سفارش شما به مبلغ '.self::toman($order->total).' پرداخت و ثبت شد و به‌زودی بررسی می‌شود.',
            "/my/orders/{$order->id}",
            [
                'order_id' => $order->id,
                'amount' => (float) $order->total,
            ]
        );
    }
}
