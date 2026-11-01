<?php

namespace App\Contracts;

use App\Models\Payment;

interface PaymentGateway
{
    public function purchase(Payment $payment): string;

    /**
     * The gateway page the customer's browser is sent to in order to pay.
     */
    public function redirectUrl(Payment $payment): string;

    public function verify(
        Payment $payment,
        string $transactionId
    ): bool;
}
