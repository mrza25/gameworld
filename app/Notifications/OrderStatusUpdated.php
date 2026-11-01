<?php

namespace App\Notifications;

use App\Models\Order;
use App\OrderStatus;
use App\PaymentStatus;

class OrderStatusUpdated extends UserNotification
{
    /**
     * The status at the moment of the change. Captured here because the
     * queued order model is re-fetched (with its latest status) when handled.
     */
    public string $status;

    public function __construct(
        public Order $order
    ) {
        $this->status = $order->status->value;

        [$title, $message] = match ($order->status) {
            OrderStatus::Pending => ["سفارش #{$order->id} در حال بررسی است", 'سفارش شما در صف بررسی قرار گرفت.'],
            OrderStatus::Processing => ["سفارش #{$order->id} در حال پردازش است", 'نصب بازی‌های سفارش شما شروع شده است.'],
            OrderStatus::Completed => ["سفارش #{$order->id} تکمیل شد", 'همه‌ی بازی‌های سفارش شما نصب شد.'],
            OrderStatus::Cancelled => ["سفارش #{$order->id} لغو شد", $order->payment_status === PaymentStatus::Refunded
                ? 'مبلغ '.self::toman($order->total).' به کیف پول شما برگشت.'
                : 'این سفارش لغو شد.'],
        };

        $this->compose('order_status', $title, $message, "/my/orders/{$order->id}", [
            'order_id' => $order->id,
            'status' => $this->status,
            'payment_status' => $order->payment_status->value,
        ]);
    }
}
