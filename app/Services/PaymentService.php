<?php

namespace App\Services;

use App\PaymentMethod;
use App\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentService
{
    public function createPendingPayment(
        User $user,
        float $amount,
        PaymentMethod $method,
        ?int $orderId = null,
        ?array $metadata = null
    ): Payment {
        if ($amount <= 0) {
            throw new RuntimeException(
                'Payment amount must be greater than zero.'
            );
        }

        return Payment::create([
            'user_id' => $user->id,
            'order_id' => $orderId,
            'amount' => $amount,
            'method' => $method,
            'status' => PaymentStatus::Pending,
            'metadata' => $metadata,
        ]);
    }

    public function markAsPaid(
        Payment $payment,
        ?string $transactionId = null
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

            $payment->update([
                'status' => PaymentStatus::Paid,
                'transaction_id' => $transactionId,
                'paid_at' => now(),
            ]);

            return $payment->fresh();
        });
    }

    public function markAsFailed(
        Payment $payment
    ): Payment {
        $payment->update([
            'status' => PaymentStatus::Failed,
        ]);

        return $payment->fresh();
    }
}
