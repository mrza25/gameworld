<?php

namespace App\Services;

use App\PaymentMethod;
use App\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\Contracts\PaymentGateway;

class CheckoutService
{
    public function __construct(
    private WalletService $walletService,
    private PaymentService $paymentService,
    private PaymentGateway $paymentGateway
) {
}

    /**
     * خرید با کیف پول
     */
    public function checkoutWithWallet(
        User $user,
        array $orderData,
        array $gameIds,
        float $amount
    ): Order {
        return DB::transaction(function () use (
            $user,
            $orderData,
            $gameIds,
            $amount
        ) {

            if ($amount <= 0) {
                throw new RuntimeException(
                    'Order amount must be greater than zero.'
                );
            }

            /*
             * Payment را ایجاد می‌کنیم.
             */
            $payment = $this->paymentService
                ->createPendingPayment(
                    $user,
                    $amount,
                    PaymentMethod::Wallet,
                    null,
                    [
                        'console_type' => $orderData['console_type'],
                        'games' => $gameIds,
                    ]
                );

            /*
             * مبلغ را از Wallet کم می‌کنیم.
             */
            $this->walletService->debit(
                $user,
                $amount,
                'payment-' . $payment->id,
                'Payment for order'
            );

            /*
             * Payment موفق شد.
             */
            $this->paymentService->markAsPaid(
                $payment,
                'wallet-' . $payment->id
            );

            /*
             * حالا که پرداخت موفق شده،
             * Order ساخته می‌شود.
             */
            $order = $this->createOrder(
                $user,
                $orderData,
                $gameIds,
                $amount
            );

            /*
             * Payment را به Order متصل می‌کنیم.
             */
            $payment->update([
                'order_id' => $order->id,
            ]);

            return $order->fresh();
        });
    }

    /**
     * ایجاد Payment برای پرداخت مستقیم با درگاه
     *
     * این متد هنوز Order نمی‌سازد.
     */
    public function createGatewayPayment(
    User $user,
    array $orderData,
    array $gameIds,
    float $amount
): Payment {
    if ($amount <= 0) {
        throw new RuntimeException(
            'Order amount must be greater than zero.'
        );
    }

    return DB::transaction(function () use (
        $user,
        $orderData,
        $gameIds,
        $amount
    ) {
        $payment = $this->paymentService
            ->createPendingPayment(
                $user,
                $amount,
                PaymentMethod::Gateway,
                null,
                [
                    'console_type' => $orderData['console_type'],
                    'games' => $gameIds,
                ]
            );

        $transactionId = $this->paymentGateway
            ->purchase($payment);

        $payment->update([
            'transaction_id' => $transactionId,
        ]);

        return $payment->fresh();
    });
}

    /**
     * ایجاد Payment برای شارژ Wallet
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

        return DB::transaction(function () use (
            $user,
            $amount
        ) {

            return $this->paymentService
                ->createPendingPayment(
                    $user,
                    $amount,
                    PaymentMethod::Gateway
                );
        });
    }

    /**
     * تکمیل شارژ Wallet بعد از تایید موفق درگاه
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

            /*
             * جلوگیری از شارژ دوباره Wallet
             */
            if ($payment->status === PaymentStatus::Paid) {
                return $payment;
            }

            /*
             * این Payment باید مربوط به شارژ Wallet باشد.
             */
            if (
                $payment->method !== PaymentMethod::Gateway ||
                $payment->order_id !== null
            ) {
                throw new RuntimeException(
                    'This payment is not a valid wallet charge.'
                );
            }

            /*
             * Payment را Paid می‌کنیم.
             */
            $payment->update([
                'status' => PaymentStatus::Paid,
                'transaction_id' => $transactionId,
                'paid_at' => now(),
            ]);

            /*
             * افزایش موجودی Wallet
             */
            $this->walletService->credit(
                $payment->user,
                (float) $payment->amount,
                'payment-' . $payment->id,
                'Wallet charged successfully'
            );

            return $payment->fresh();
        });
    }

    /**
     * ساخت Order و OrderItems
     *
     * این متد فقط بعد از پرداخت موفق اجرا می‌شود.
     */
    private function createOrder(
        User $user,
        array $orderData,
        array $gameIds,
        float $amount
    ): Order {
        $games = \App\Models\Game::whereIn(
            'id',
            $gameIds
        )->get();

        if ($games->count() !== count($gameIds)) {
            throw new RuntimeException(
                'One or more selected games do not exist.'
            );
        }

        $order = Order::create([
            'user_id' => $user->id,
            'console_type' => $orderData['console_type'],
            'total' => $amount,
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        foreach ($games as $game) {

            $order->items()->create([
                'game_id' => $game->id,
                'quantity' => 1,
                'price' => $game->price,
                'installation_status' => 'pending',
            ]);
        }

        return $order;
    }
    /**
 * تکمیل خرید بعد از تایید موفق درگاه
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

        if ($payment->status === PaymentStatus::Paid) {
            if ($payment->order_id) {
                return $payment->order()->firstOrFail();
            }

            throw new RuntimeException(
                'Payment is already paid but has no order.'
            );
        }

        if (
            $payment->method !== PaymentMethod::Gateway ||
            $payment->order_id !== null
        ) {
            throw new RuntimeException(
                'This payment is not a valid gateway checkout.'
            );
        }

        if (
            !$this->paymentGateway->verify(
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
            !is_array($metadata) ||
            empty($metadata['console_type']) ||
            empty($metadata['games'])
        ) {
            throw new RuntimeException(
                'Payment metadata is invalid.'
            );
        }

        $payment->update([
            'status' => PaymentStatus::Paid,
            'transaction_id' => $transactionId,
            'paid_at' => now(),
        ]);

        $order = $this->createOrder(
            $payment->user,
            [
                'console_type' => $metadata['console_type'],
            ],
            $metadata['games'],
            (float) $payment->amount
        );

        $payment->update([
            'order_id' => $order->id,
        ]);

        return $order->fresh();
    });
}
}
