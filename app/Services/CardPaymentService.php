<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\CardPaymentReviewed;
use App\Notifications\ReceiptSubmitted;
use App\Notifications\WalletCharged;
use App\Notifications\WalletChargeRejected;
use App\OrderStatus;
use App\PaymentMethod;
use App\PaymentStatus;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Card-to-card payments: the customer transfers the money by hand and uploads a
 * photo of the receipt, and an admin confirms it before anything happens. This
 * pays for an order or tops up the wallet.
 */
class CardPaymentService
{
    /**
     * Receipts hold banking details, so they live on the private disk.
     */
    public const RECEIPT_DISK = 'local';

    public function __construct(
        private PaymentService $paymentService,
        private WalletService $walletService
    ) {}

    /**
     * The card customers transfer to, or null while the method is not set up.
     *
     * @return array{number: string, holder: ?string, bank: ?string}|null
     */
    public static function destinationCard(): ?array
    {
        $number = config('services.card_to_card.number');

        if (blank($number)) {
            return null;
        }

        return [
            'number' => (string) $number,
            'holder' => config('services.card_to_card.holder'),
            'bank' => config('services.card_to_card.bank'),
        ];
    }

    public static function paymentHours(): int
    {
        return max(1, (int) config('services.card_to_card.payment_hours'));
    }

    /**
     * Store a receipt for an order that is waiting for payment. A rejected
     * receipt stays on record, so the new one opens a new payment.
     *
     * @param  array{tracking_code?: ?string, card_last4?: ?string}  $details
     *
     * @throws RuntimeException when the order cannot take a receipt right now
     */
    public function submitReceipt(Order $order, UploadedFile $receipt, array $details = []): Order
    {
        return $this->storingReceipt($receipt, function (array $receiptFields) use ($order, $details) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->isAwaitingPayment()) {
                throw new RuntimeException('این سفارش در انتظار پرداخت نیست.');
            }

            $payment = $this->latestCardPayment($order);

            if ($payment?->hasReceiptInReview()) {
                throw new RuntimeException('رسید شما در حال بررسی است. لطفاً منتظر نتیجه بمانید.');
            }

            if ($payment?->status !== PaymentStatus::Pending) {
                $payment = $this->paymentService->createPendingPayment(
                    $order->user,
                    (float) $order->total,
                    PaymentMethod::CardToCard,
                    $order->id
                );
            }

            $payment->update($receiptFields + $details);
            $order->recordHistory(OrderHistory::EVENT_RECEIPT_SUBMITTED);
            $this->alertAdmins($payment);

            return $order;
        });
    }

    /**
     * Confirm the receipt under review. The order then enters the normal flow.
     *
     * @throws RuntimeException when there is no receipt to confirm
     */
    public function approve(Order $order, User $admin): Order
    {
        return DB::transaction(function () use ($order, $admin) {
            [$order, $payment] = $this->lockOrderWithReceiptInReview($order);

            $this->markApproved($payment, $admin);

            $order->update([
                'status' => OrderStatus::Pending,
                'payment_status' => PaymentStatus::Paid,
                'payment_due_at' => null,
            ]);

            $order->user?->notify(new CardPaymentReviewed($order, true));

            return $order;
        });
    }

    /**
     * Reject the receipt under review. The order keeps waiting, with a fresh
     * deadline, so the customer can send a better receipt or cancel.
     *
     * @throws RuntimeException when there is no receipt to reject
     */
    public function reject(Order $order, User $admin, string $reason): Order
    {
        return DB::transaction(function () use ($order, $admin, $reason) {
            [$order, $payment] = $this->lockOrderWithReceiptInReview($order);

            $this->markRejected($payment, $admin, $reason);

            $order->update(['payment_due_at' => now()->addHours(self::paymentHours())]);
            $order->recordHistory(OrderHistory::EVENT_RECEIPT_REJECTED, note: $reason);

            $order->user?->notify(new CardPaymentReviewed($order, false, $reason));

            return $order;
        });
    }

    /**
     * Open a wallet top-up to be paid by card-to-card transfer. Nothing is
     * credited until an admin confirms the receipt.
     *
     * @throws RuntimeException when card-to-card is not set up
     */
    public function createWalletCharge(User $user, float $amount): Payment
    {
        if (self::destinationCard() === null) {
            throw new RuntimeException('پرداخت کارت به کارت در حال حاضر فعال نیست.');
        }

        return $this->paymentService->createPendingPayment($user, $amount, PaymentMethod::CardToCard);
    }

    /**
     * Store the receipt of a wallet top-up. Unlike an order there is nothing to
     * keep waiting, so a rejected top-up is final and the customer opens a new one.
     *
     * @param  array{tracking_code?: ?string, card_last4?: ?string}  $details
     *
     * @throws RuntimeException when the top-up cannot take a receipt right now
     */
    public function submitWalletChargeReceipt(Payment $payment, UploadedFile $receipt, array $details = []): Payment
    {
        return $this->storingReceipt($receipt, function (array $receiptFields) use ($payment, $details) {
            $payment = Payment::query()->cardWalletCharges()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->hasReceiptInReview()) {
                throw new RuntimeException('رسید شما در حال بررسی است. لطفاً منتظر نتیجه بمانید.');
            }

            if ($payment->status !== PaymentStatus::Pending) {
                throw new RuntimeException('این درخواست شارژ دیگر فعال نیست. لطفاً درخواست جدیدی ثبت کنید.');
            }

            $payment->update($receiptFields + $details);
            $this->alertAdmins($payment);

            return $payment;
        });
    }

    /**
     * Confirm the receipt of a wallet top-up and credit the wallet.
     *
     * @throws RuntimeException when there is no receipt to confirm
     */
    public function approveWalletCharge(Payment $payment, User $admin): Payment
    {
        return DB::transaction(function () use ($payment, $admin) {
            $payment = $this->lockWalletChargeInReview($payment);

            $this->markApproved($payment, $admin);

            $wallet = $this->walletService->credit(
                $payment->user,
                (float) $payment->amount,
                'payment-'.$payment->id,
                'شارژ کیف پول با کارت به کارت'
            );

            $payment->user->notify(new WalletCharged($payment, (float) $wallet->balance));

            return $payment;
        });
    }

    /**
     * Reject the receipt of a wallet top-up, telling the customer why.
     *
     * @throws RuntimeException when there is no receipt to reject
     */
    public function rejectWalletCharge(Payment $payment, User $admin, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $admin, $reason) {
            $payment = $this->lockWalletChargeInReview($payment);

            $this->markRejected($payment, $admin, $reason);

            $payment->user->notify(new WalletChargeRejected($payment, $reason));

            return $payment;
        });
    }

    /**
     * Store the file, then run the callback in a transaction with the fields to
     * save on the payment. The file is removed again if that fails.
     *
     * @template TResult
     *
     * @param  Closure(array<string, mixed>): TResult  $callback
     * @return TResult
     */
    private function storingReceipt(UploadedFile $receipt, Closure $callback): mixed
    {
        $hash = hash_file('sha256', $receipt->getRealPath());
        $path = $receipt->store('receipts', self::RECEIPT_DISK);

        try {
            return DB::transaction(fn () => $callback([
                'receipt_path' => $path,
                'receipt_hash' => $hash,
                'receipt_uploaded_at' => now(),
            ]));
        } catch (Throwable $e) {
            Storage::disk(self::RECEIPT_DISK)->delete($path);

            throw $e;
        }
    }

    private function alertAdmins(Payment $payment): void
    {
        Notification::send(User::query()->where('is_admin', true)->get(), new ReceiptSubmitted($payment));
    }

    private function markApproved(Payment $payment, User $admin): void
    {
        $payment->update([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
    }

    private function markRejected(Payment $payment, User $admin, string $reason): void
    {
        $payment->update([
            'status' => PaymentStatus::Failed,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_note' => $reason,
        ]);
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function lockOrderWithReceiptInReview(Order $order): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($order->id);
        $payment = $this->latestCardPayment($order);

        if (! $order->isAwaitingPayment() || ! $payment?->hasReceiptInReview()) {
            throw new RuntimeException('این سفارش رسیدی برای بررسی ندارد.');
        }

        return [$order, $payment];
    }

    private function lockWalletChargeInReview(Payment $payment): Payment
    {
        $payment = Payment::query()->cardWalletCharges()->lockForUpdate()->findOrFail($payment->id);

        if (! $payment->hasReceiptInReview()) {
            throw new RuntimeException('این درخواست شارژ رسیدی برای بررسی ندارد.');
        }

        return $payment;
    }

    private function latestCardPayment(Order $order): ?Payment
    {
        return $order->payments()
            ->where('method', PaymentMethod::CardToCard)
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }
}
