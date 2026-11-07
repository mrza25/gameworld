<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Coupon;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\GatewayPaymentFailed;
use App\Notifications\OrderAwaitingCardPayment;
use App\Notifications\OrderPlaced;
use App\Notifications\WalletCharged;
use App\OrderStatus;
use App\PaymentMethod;
use App\PaymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CheckoutService
{
    public function __construct(
        private WalletService $walletService,
        private PaymentService $paymentService,
        private PaymentGateway $paymentGateway,
        private CouponService $couponService
    ) {}

    /**
     * Checkout using wallet.
     * The order is created only after the wallet payment succeeds.
     */
    public function checkoutWithWallet(
        User $user,
        array $orderData,
        array $variantIds,
        float $amount,
        array $optionIdsByVariant = [],
        ?string $couponCode = null
    ): Order {
        return DB::transaction(function () use (
            $user,
            $orderData,
            $variantIds,
            $amount,
            $optionIdsByVariant,
            $couponCode
        ) {
            $snapshot = $this->buildCheckoutSnapshot(
                $orderData,
                $variantIds,
                $amount,
                $optionIdsByVariant
            );

            $pricing = $this->priceWithCoupon($user, $snapshot['total'], $couponCode);

            $payment = $this->paymentService->createPendingPayment(
                $user,
                $pricing['total'],
                PaymentMethod::Wallet,
                null,
                [
                    'console_type' => $orderData['console_type'],
                    'console_note' => $orderData['console_note'] ?? null,
                    'items' => $snapshot['items'],
                ]
            );

            $this->walletService->debit(
                $user,
                $pricing['total'],
                'payment-'.$payment->id,
                'Payment for order'
            );

            $this->paymentService->markAsPaid(
                $payment,
                'wallet-'.$payment->id
            );

            $order = $this->createOrderFromSnapshot(
                $user,
                $orderData,
                $snapshot['items'],
                $pricing['total'],
                OrderStatus::Pending,
                PaymentStatus::Paid,
                $pricing
            );

            $payment->update([
                'order_id' => $order->id,
            ]);

            $user->notify(new OrderPlaced($order));

            return $order->fresh();
        });
    }

    /**
     * Place an order to be paid by card-to-card transfer. Unlike the other
     * methods the order exists before the money does: this locks the price the
     * customer has to transfer, and an admin confirms the payment later.
     */
    public function createCardPaymentOrder(
        User $user,
        array $orderData,
        array $variantIds,
        float $amount,
        array $optionIdsByVariant = [],
        ?string $couponCode = null
    ): Order {
        if (CardPaymentService::destinationCard() === null) {
            throw new RuntimeException(
                'پرداخت کارت به کارت در حال حاضر فعال نیست.'
            );
        }

        return DB::transaction(function () use (
            $user,
            $orderData,
            $variantIds,
            $amount,
            $optionIdsByVariant,
            $couponCode
        ) {
            $snapshot = $this->buildCheckoutSnapshot(
                $orderData,
                $variantIds,
                $amount,
                $optionIdsByVariant
            );

            $pricing = $this->priceWithCoupon($user, $snapshot['total'], $couponCode);

            $order = $this->createOrderFromSnapshot(
                $user,
                $orderData,
                $snapshot['items'],
                $pricing['total'],
                OrderStatus::AwaitingPayment,
                PaymentStatus::Pending,
                $pricing
            );

            $order->update([
                'payment_due_at' => now()->addHours(CardPaymentService::paymentHours()),
            ]);

            $this->paymentService->createPendingPayment(
                $user,
                $pricing['total'],
                PaymentMethod::CardToCard,
                $order->id
            );

            $user->notify(new OrderAwaitingCardPayment($order));

            return $order->fresh();
        });
    }

    /**
     * Create a pending payment for gateway checkout.
     * This method does not create an order.
     */
    public function createGatewayPayment(
        User $user,
        array $orderData,
        array $variantIds,
        float $amount,
        array $optionIdsByVariant = [],
        ?string $couponCode = null
    ): Payment {
        return DB::transaction(function () use (
            $user,
            $orderData,
            $variantIds,
            $amount,
            $optionIdsByVariant,
            $couponCode
        ) {
            $snapshot = $this->buildCheckoutSnapshot(
                $orderData,
                $variantIds,
                $amount,
                $optionIdsByVariant
            );

            $pricing = $this->priceWithCoupon($user, $snapshot['total'], $couponCode);

            $payment = $this->paymentService->createPendingPayment(
                $user,
                $pricing['total'],
                PaymentMethod::Gateway,
                null,
                [
                    'console_type' => $orderData['console_type'],
                    'console_note' => $orderData['console_note'] ?? null,
                    'items' => $snapshot['items'],
                    // The use is only counted once the gateway confirms payment.
                    'coupon' => $pricing['coupon'] ? [
                        'id' => $pricing['coupon']->id,
                        'code' => $pricing['coupon']->code,
                        'discount' => number_format($pricing['discount'], 2, '.', ''),
                    ] : null,
                ]
            );

            $transactionId = $this->paymentGateway->purchase($payment);

            $payment->update([
                'transaction_id' => $transactionId,
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Create a pending payment for wallet top-up.
     * Kept separate from game checkout.
     */
    public function createWalletChargePayment(
        User $user,
        float $amount
    ): Payment {
        if ($amount <= 0) {
            throw new RuntimeException(
                'Wallet charge amount must be greater than zero.'
            );
        }

        return DB::transaction(function () use ($user, $amount) {
            $payment = $this->paymentService->createPendingPayment(
                $user,
                $amount,
                PaymentMethod::Gateway
            );

            $transactionId = $this->paymentGateway->purchase($payment);

            $payment->update([
                'transaction_id' => $transactionId,
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Complete a wallet top-up after gateway verification.
     */
    public function completeWalletCharge(
        Payment $payment,
        string $transactionId
    ): Payment {
        return DB::transaction(function () use (
            $payment,
            $transactionId
        ) {
            $payment = Payment::query()
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($payment->status === PaymentStatus::Paid) {
                if (
                    $payment->method !== PaymentMethod::Gateway ||
                    ! $payment->matchesTransaction($transactionId)
                ) {
                    throw new RuntimeException(
                        'Payment verification failed.'
                    );
                }

                return $payment;
            }

            if (
                $payment->method !== PaymentMethod::Gateway ||
                ! $payment->isWalletCharge()
            ) {
                throw new RuntimeException(
                    'This payment is not a valid wallet charge.'
                );
            }

            if (
                ! $this->paymentGateway->verify(
                    $payment,
                    $transactionId
                )
            ) {
                throw new RuntimeException(
                    'Payment verification failed.'
                );
            }

            $payment->update([
                'status' => PaymentStatus::Paid,
                'transaction_id' => $transactionId,
                'paid_at' => now(),
            ]);

            $wallet = $this->walletService->credit(
                $payment->user,
                (float) $payment->amount,
                'payment-'.$payment->id,
                'Wallet charged successfully'
            );

            $payment->user->notify(new WalletCharged($payment, (float) $wallet->balance));

            return $payment->fresh();
        });
    }

    /**
     * Mark a pending gateway payment as failed after the gateway reports that
     * the customer cancelled it. A payment whose transaction id does not
     * match, or that is no longer pending, is left untouched.
     */
    public function failGatewayPayment(
        Payment $payment,
        string $transactionId
    ): void {
        DB::transaction(function () use (
            $payment,
            $transactionId
        ) {
            $payment = Payment::query()
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if (
                $payment->status === PaymentStatus::Pending &&
                $payment->matchesTransaction($transactionId)
            ) {
                $this->paymentService->markAsFailed($payment);
                $payment->user->notify(new GatewayPaymentFailed($payment));
            }
        });
    }

    /**
     * Complete a game purchase after successful gateway verification.
     */
    public function completeGatewayCheckout(
        Payment $payment,
        string $transactionId
    ): Order {
        return DB::transaction(function () use (
            $payment,
            $transactionId
        ) {
            $payment = Payment::query()
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if (
                $payment->status === PaymentStatus::Paid &&
                $payment->order_id !== null
            ) {
                if (
                    $payment->method !== PaymentMethod::Gateway ||
                    ! $payment->matchesTransaction($transactionId)
                ) {
                    throw new RuntimeException(
                        'Payment verification failed.'
                    );
                }

                return Order::query()
                    ->findOrFail($payment->order_id);
            }

            if (
                $payment->status === PaymentStatus::Paid ||
                $payment->method !== PaymentMethod::Gateway ||
                $payment->order_id !== null
            ) {
                throw new RuntimeException(
                    'This payment is not a valid gateway checkout.'
                );
            }

            if (
                ! $this->paymentGateway->verify(
                    $payment,
                    $transactionId
                )
            ) {
                throw new RuntimeException(
                    'Payment verification failed.'
                );
            }

            $metadata = $payment->metadata;

            if (
                ! is_array($metadata) ||
                empty($metadata['console_type']) ||
                empty($metadata['items']) ||
                ! is_array($metadata['items'])
            ) {
                throw new RuntimeException(
                    'Payment metadata is invalid.'
                );
            }

            $total = $this->calculateSnapshotTotal(
                $metadata['items']
            );

            $discount = (float) ($metadata['coupon']['discount'] ?? 0);
            $payable = round($total - $discount, 2);

            if (
                $total <= 0 ||
                $this->toCents($payment->amount) !==
                    $this->toCents($payable)
            ) {
                throw new RuntimeException(
                    'Payment amount does not match the order items.'
                );
            }

            // Verify payment before creating the order.
            $payment->update([
                'status' => PaymentStatus::Paid,
                'transaction_id' => $transactionId,
                'paid_at' => now(),
            ]);

            $order = $this->createOrderFromSnapshot(
                $payment->user,
                [
                    'console_type' => $metadata['console_type'],
                    'console_note' => $metadata['console_note'] ?? null,
                ],
                $metadata['items'],
                $payable,
                OrderStatus::Pending,
                PaymentStatus::Paid,
                [
                    'subtotal' => $total,
                    'discount' => $discount,
                    'coupon_code' => $metadata['coupon']['code'] ?? null,
                    // The customer already paid this price, so it holds even if the coupon ran out meanwhile.
                    'coupon' => isset($metadata['coupon']['id']) ? Coupon::query()->lockForUpdate()->find($metadata['coupon']['id']) : null,
                ]
            );

            $payment->update([
                'order_id' => $order->id,
            ]);

            $payment->user->notify(new OrderPlaced($order));

            return $order->fresh();
        });
    }

    /**
     * Build a trusted snapshot of the selected variants and their final prices.
     * An item's price is its chosen option's price when the variant has options.
     * The option is copied here, so a gateway payment keeps what the customer
     * agreed to even if the option changes meanwhile.
     *
     * @param  array<int, int>  $optionIdsByVariant
     */
    private function buildCheckoutSnapshot(
        array $orderData,
        array $variantIds,
        float $requestedAmount,
        array $optionIdsByVariant = []
    ): array {
        $consoleType = $orderData['console_type'] ?? null;

        if (
            ! $consoleType ||
            ! is_array($variantIds) ||
            count($variantIds) === 0
        ) {
            throw new RuntimeException(
                'Your checkout selection is invalid. Please select your games again.'
            );
        }

        $uniqueIds = array_unique($variantIds);

        if (count($uniqueIds) !== count($variantIds)) {
            throw new RuntimeException(
                'Duplicate game versions are not allowed.'
            );
        }

        $variants = GameVariant::query()
            ->with(['game', 'platform', 'activeOptions'])
            ->whereIn('id', $uniqueIds)
            ->where('is_available', true)
            ->whereHas('game')
            ->whereHas('platform')
            ->lockForUpdate()
            ->get();

        if ($variants->count() !== count($uniqueIds)) {
            throw new RuntimeException(
                'One or more selected game versions are unavailable.'
            );
        }

        $platformSlug = Str::slug($consoleType);
        $items = [];

        foreach ($variants as $variant) {
            $variantSlug = $variant->platform?->slug ?? '';
            $variantName = $variant->platform?->name ?? '';

            $isMatch = ($variantSlug === $platformSlug)
                || ($variantName === $consoleType)
                || ($variantSlug === $consoleType)
                || (Str::slug($variantName) === $platformSlug)
                || ($variantSlug === 'ps5' && in_array($platformSlug, ['ps5', 'playstation-5'], true))
                || ($variantSlug === 'ps4' && in_array($platformSlug, ['ps4', 'playstation-4'], true))
                || ($platformSlug === 'other' || $consoleType === 'other' || $consoleType === 'سایر کنسول‌ها');

            if (! $isMatch) {
                throw new RuntimeException(
                    'A selected game version does not match your console.'
                );
            }

            $option = $variant->resolveOption($optionIdsByVariant[$variant->id] ?? null);
            $price = round($variant->priceWith($option), 2);

            if ($price < 0) {
                throw new RuntimeException(
                    'A selected game has an invalid price.'
                );
            }

            $items[] = [
                'game_variant_id' => $variant->id,
                'game_id' => $variant->game_id,
                'title' => $variant->game->title,
                'platform_name' => $variant->platform->name,
                'quantity' => 1,
                'price' => number_format($price, 2, '.', ''),
                'option' => $option ? [
                    'id' => $option->id,
                    'label' => $option->label,
                    'price' => number_format((float) $option->price, 2, '.', ''),
                ] : null,
            ];
        }

        $total = $this->calculateSnapshotTotal($items);

        if ($total <= 0) {
            throw new RuntimeException(
                'Order amount must be greater than zero.'
            );
        }

        if ($this->toCents($requestedAmount) !== $this->toCents($total)) {
            throw new RuntimeException(
                'The checkout total has changed. Please review your order again.'
            );
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Create the order using the prices saved in the payment snapshot.
     * Current variant prices are deliberately not queried here.
     *
     * @param  array{subtotal?: float, discount?: float, coupon?: ?Coupon, coupon_code?: ?string}  $pricing
     */
    private function createOrderFromSnapshot(
        User $user,
        array $orderData,
        array $items,
        float $amount,
        OrderStatus $status = OrderStatus::Pending,
        PaymentStatus $paymentStatus = PaymentStatus::Paid,
        array $pricing = []
    ): Order {
        $order = Order::create([
            'user_id' => $user->id,
            'console_type' => $orderData['console_type'],
            'console_note' => $orderData['console_note'] ?? null,
            'subtotal' => $pricing['subtotal'] ?? $amount,
            'discount_amount' => $pricing['discount'] ?? 0,
            'coupon_code' => $pricing['coupon_code'] ?? null,
            'total' => $amount,
            'status' => $status,
            'payment_status' => $paymentStatus,
        ]);

        foreach ($items as $item) {
            $orderItem = $order->items()->create([
                'game_id' => $item['game_id'],
                'game_variant_id' => $item['game_variant_id'],
                'title' => $item['title'] ?? null,
                'platform_name' => $item['platform_name'] ?? null,
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'installation_status' => 'pending',
            ]);

            if (! empty($item['option'])) {
                $orderItem->selectedOption()->create([
                    'game_variant_option_id' => $item['option']['id'],
                    'label' => $item['option']['label'],
                    'price' => $item['option']['price'],
                ]);
            }
        }

        if (($pricing['coupon'] ?? null) !== null) {
            $this->couponService->redeem($pricing['coupon'], $order, $pricing['discount']);
        }

        return $order;
    }

    /**
     * What the customer pays after a coupon. The coupon stays locked until the
     * transaction ends, so its limits cannot be passed by orders placed together.
     *
     * @return array{subtotal: float, discount: float, total: float, coupon: ?Coupon, coupon_code: ?string}
     */
    private function priceWithCoupon(User $user, float $subtotal, ?string $couponCode): array
    {
        if (blank($couponCode)) {
            return ['subtotal' => $subtotal, 'discount' => 0.0, 'total' => $subtotal, 'coupon' => null, 'coupon_code' => null];
        }

        $quote = $this->couponService->quote($couponCode, $user, $subtotal);

        return [
            'subtotal' => $subtotal,
            'discount' => $quote['discount'],
            'total' => $quote['payable'],
            'coupon' => $quote['coupon'],
            'coupon_code' => $quote['coupon']->code,
        ];
    }

    /**
     * Calculate a total from saved item prices using integer cents.
     */
    private function calculateSnapshotTotal(array $items): float
    {
        $totalCents = 0;

        foreach ($items as $item) {
            if (
                ! isset(
                    $item['game_variant_id'],
                    $item['game_id'],
                    $item['quantity'],
                    $item['price']
                ) ||
                ! is_numeric($item['quantity']) ||
                ! is_numeric($item['price']) ||
                (int) $item['quantity'] < 1 ||
                (float) $item['price'] < 0
            ) {
                throw new RuntimeException(
                    'Payment item data is invalid.'
                );
            }

            $totalCents +=
                $this->toCents($item['price']) *
                (int) $item['quantity'];
        }

        return $totalCents / 100;
    }

    private function toCents(float|int|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
