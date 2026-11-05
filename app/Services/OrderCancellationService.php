<?php

namespace App\Services;

use App\Models\Order;
use App\Notifications\OrderStatusUpdated;
use App\OrderStatus;
use App\PaymentMethod;
use App\PaymentStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderCancellationService
{
    public function __construct(
        private WalletService $walletService
    ) {}

    /**
     * Cancel an order and refund what was paid to the customer's wallet.
     *
     * Customers can only cancel before installation starts; admins can also
     * cancel orders that are being processed. Completed orders cannot be cancelled.
     *
     * A receipt under review blocks everyone: the money may already have been
     * transferred, so an admin must approve or reject it first. An approved
     * order is then refunded to the wallet like any other paid order.
     */
    public function cancel(Order $order, bool $byAdmin = false): Order
    {
        return DB::transaction(function () use ($order, $byAdmin) {
            $order = Order::query()->with(['items', 'payments'])->lockForUpdate()->findOrFail($order->id);

            if ($order->status === OrderStatus::Cancelled) {
                throw new RuntimeException('این سفارش قبلاً لغو شده است.');
            }

            if ($order->status === OrderStatus::Completed) {
                throw new RuntimeException('سفارش تکمیل‌شده قابل لغو نیست.');
            }

            if ($order->isAwaitingPayment() && $order->latestCardPayment()?->hasReceiptInReview()) {
                throw new RuntimeException($byAdmin
                    ? 'رسید این سفارش در حال بررسی است. ابتدا آن را تأیید یا رد کنید.'
                    : 'رسید شما در حال بررسی است و تا مشخص شدن نتیجه نمی‌توانید سفارش را لغو کنید.');
            }

            if (! $byAdmin && ! $order->isCancellableByCustomer()) {
                throw new RuntimeException('نصب این سفارش شروع شده و دیگر قابل لغو نیست.');
            }

            if ($order->payment_status === PaymentStatus::Paid) {
                $this->walletService->credit(
                    $order->user,
                    (float) $order->total,
                    'order-refund-'.$order->id,
                    "بازگشت وجه سفارش #{$order->id}"
                );

                $order->payments()
                    ->where('status', PaymentStatus::Paid)
                    ->update(['status' => PaymentStatus::Refunded]);

                $order->payment_status = PaymentStatus::Refunded;
            }

            if ($order->isAwaitingPayment()) {
                $order->payments()
                    ->where('method', PaymentMethod::CardToCard)
                    ->where('status', PaymentStatus::Pending)
                    ->update(['status' => PaymentStatus::Failed]);

                $order->payment_status = PaymentStatus::Failed;
                $order->payment_due_at = null;
            }

            $order->status = OrderStatus::Cancelled;
            $order->save();

            $order->user?->notify(new OrderStatusUpdated($order));

            return $order->fresh(['items.game', 'items.gameVariant.platform', 'items.selectedOption', 'payments', 'user']);
        });
    }
}
