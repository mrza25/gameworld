<?php

namespace App\Contracts;

use App\Models\Payment;

interface PaymentGateway
{
    public function purchase(Payment $payment): string;

    public function verify(
        Payment $payment,
        string $transactionId
    ): bool;
}
