<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sandbox gateway: the customer pays on our own test page
 * (see FakeGatewayController) and no real money moves.
 */
class FakePaymentGateway implements PaymentGateway
{
    public const STATUS_APPROVED = 'approved';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Paying here costs nothing, so a live shop must never accept it.
     */
    public static function isEnabled(): bool
    {
        return ! app()->isProduction() || config('services.payment.allow_fake_gateway');
    }

    public function purchase(Payment $payment): string
    {
        if (! self::isEnabled()) {
            throw new RuntimeException('درگاه پرداخت در حال حاضر فعال نیست.');
        }

        $transactionId = 'FAKE-'.Str::upper(
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

    public function redirectUrl(Payment $payment): string
    {
        return route('payments.fake-gateway.show', [
            'payment' => $payment->id,
            'transaction_id' => $payment->transaction_id,
        ]);
    }

    /**
     * A payment the customer cancelled on the sandbox page never verifies,
     * just as a real gateway would not confirm an abandoned payment.
     */
    public function verify(
        Payment $payment,
        string $transactionId
    ): bool {
        $expectedTransactionId =
            $payment->metadata['gateway_transaction_id'] ?? null;

        return self::isEnabled()
            && $expectedTransactionId === $transactionId
            && ($payment->metadata['gateway_status'] ?? null) !== self::STATUS_CANCELLED;
    }

    /**
     * Record whether the customer paid or cancelled on the sandbox page.
     */
    public function recordDecision(
        Payment $payment,
        bool $approved
    ): void {
        $payment->update([
            'metadata' => array_merge(
                $payment->metadata ?? [],
                [
                    'gateway_status' => $approved
                        ? self::STATUS_APPROVED
                        : self::STATUS_CANCELLED,
                ]
            ),
        ]);
    }
}
