<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Str;

class FakePaymentGateway implements PaymentGateway
{
    public function purchase(Payment $payment): string
    {
        $transactionId = 'FAKE-' . Str::upper(
            Str::random(16)
        );

        $payment->update([
            'metadata' => array_merge(
                $payment->metadata ?? [],
                [
                    'gateway_transaction_id' => $transactionId,
                ]
            ),
        ]);

        return $transactionId;
    }

    public function verify(
        Payment $payment,
        string $transactionId
    ): bool {
        $expectedTransactionId =
            $payment->metadata['gateway_transaction_id'] ?? null;

        return $expectedTransactionId === $transactionId;
    }
}
