<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Str;

class FakePaymentGateway implements PaymentGateway
{
    public function purchase(Payment $payment): string
    {
        return 'FAKE-' . Str::upper(Str::random(16));
    }

    public function verify(
        Payment $payment,
        string $transactionId
    ): bool {
        return str_starts_with($transactionId, 'FAKE-');
    }
}
