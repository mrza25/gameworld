<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
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
        private PaymentGateway $paymentGateway
    ) {}

    /**
     * Checkout using wallet.
     * The order is created only after the wallet payment succeeds.
     */
    public function checkoutWithWallet(
        User $user,
        array $orderData,
        array $variantIds,
        float $amount
    ): Order {
        return DB::transaction(function () use (
            $user,
            $orderData,
            $variantIds,
            $amount
        ) {
            $snapshot = $this->buildCheckoutSnapshot(
                $orderData,
                $variantIds,
                $amount
            );

            $payment = $this->paymentService->createPendingPayment(
                $user,
                $snapshot['total'],
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
                $snapshot['total'],
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
                $snapshot['total']
            );

            $payment->update([
                'order_id' => $order->id,
            ]);

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
        float $amount
    ): Payment {
        return DB::transaction(function () use (
            $user,
            $orderData,
            $variantIds,
            $amount
        ) {
            $snapshot = $this->buildCheckoutSnapshot(
                $orderData,
                $variantIds,
                $amount
            );

            $payment = $this->paymentService->createPendingPayment(
                $user,
                $snapshot['total'],
                PaymentMethod::Gateway,
                null,
                [
                    'console_type' => $orderData['console_type'],
                    'console_note' => $orderData['console_note'] ?? null,
                    'items' => $snapshot['items'],
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
                return $payment;
            }

            if (
                $payment->method !== PaymentMethod::Gateway ||
                $payment->order_id !== null ||
                ! empty($payment->metadata['items'])
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

            $this->walletService->credit(
                $payment->user,
                (float) $payment->amount,
                'payment-'.$payment->id,
                'Wallet charged successfully'
            );

            return $payment->fresh();
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

            if (
                $total <= 0 ||
                $this->toCents($payment->amount) !==
                    $this->toCents($total)
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
                $total
            );

            $payment->update([
                'order_id' => $order->id,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Build a trusted snapshot of the selected variants and their prices.
     */
    private function buildCheckoutSnapshot(
        array $orderData,
        array $variantIds,
        float $requestedAmount
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
            ->with(['game', 'platform'])
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

            $price = round((float) $variant->price, 2);

            if ($price < 0) {
                throw new RuntimeException(
                    'A selected game has an invalid price.'
                );
            }

            $items[] = [
                'game_variant_id' => $variant->id,
                'game_id' => $variant->game_id,
                'quantity' => 1,
                'price' => number_format($price, 2, '.', ''),
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
     */
    private function createOrderFromSnapshot(
        User $user,
        array $orderData,
        array $items,
        float $amount
    ): Order {
        $order = Order::create([
            'user_id' => $user->id,
            'console_type' => $orderData['console_type'],
            'console_note' => $orderData['console_note'] ?? null,
            'total' => $amount,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        foreach ($items as $item) {
            $order->items()->create([
                'game_id' => $item['game_id'],
                'game_variant_id' => $item['game_variant_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'installation_status' => 'pending',
            ]);
        }

        return $order;
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
