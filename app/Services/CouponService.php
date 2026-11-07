<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\User;
use RuntimeException;

class CouponService
{
    /**
     * Check a coupon for this customer and amount. Inside a transaction this
     * locks the coupon's row, so two customers cannot take its last use at once.
     *
     * @return array{coupon: Coupon, discount: float, payable: float}
     *
     * @throws RuntimeException with a message safe to show the customer
     */
    public function quote(string $code, User $user, float $amount): array
    {
        $coupon = Coupon::query()
            ->where('code', Coupon::normalizeCode($code))
            ->lockForUpdate()
            ->first();

        if ($coupon === null) {
            throw new RuntimeException('کد تخفیف معتبر نیست.');
        }

        $reason = $coupon->unavailableReason();

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        if ($amount < (float) $coupon->min_order_amount) {
            throw new RuntimeException('حداقل مبلغ سفارش برای این کد تخفیف '.number_format((float) $coupon->min_order_amount).' تومان است.');
        }

        if (
            $coupon->per_user_limit !== null
            && $coupon->usages()->where('user_id', $user->id)->count() >= $coupon->per_user_limit
        ) {
            throw new RuntimeException('سهم شما از این کد تخفیف تمام شده است.');
        }

        $discount = $coupon->discountFor($amount);

        // An order always has something to pay, so every payment method keeps working as it does.
        if ($discount >= $amount) {
            throw new RuntimeException('این کد تخفیف برای مبلغ این سفارش قابل استفاده نیست.');
        }

        return [
            'coupon' => $coupon,
            'discount' => $discount,
            'payable' => round($amount - $discount, 2),
        ];
    }

    public function redeem(Coupon $coupon, Order $order, float $discount): void
    {
        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'discount_amount' => $discount,
        ]);

        $coupon->increment('used_count');
    }

    /**
     * Give the use back when an order is cancelled, so an unpaid order that
     * expires does not use up a limited coupon.
     */
    public function release(Order $order): void
    {
        $usage = CouponUsage::query()->where('order_id', $order->id)->first();

        if ($usage === null) {
            return;
        }

        Coupon::query()->whereKey($usage->coupon_id)->where('used_count', '>', 0)->decrement('used_count');

        $usage->delete();
    }
}
