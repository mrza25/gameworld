<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\PaymentStatus;
use App\Services\CheckoutService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class WalletController extends Controller
{
    public function __construct(
        private WalletService $walletService,
        private CheckoutService $checkoutService
    ) {
    }

    /**
     * Get wallet balance and transactions.
     */
    public function index(Request $request): JsonResponse
    {
        $wallet = $this->walletService->getOrCreateWallet($request->user());

        $transactions = $wallet->transactions()
            ->latest()
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'balanceAfter' => (float) $t->balance_after,
                'reference' => $t->reference,
                'description' => $t->description,
                'date' => $t->created_at?->format('Y-m-d H:i'),
                'created_at' => $t->created_at,
            ]);

        return response()->json([
            'ok' => true,
            'balance' => (float) $wallet->balance,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Initiate a gateway top-up.
     */
    public function charge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:50000', 'max:50000000'],
        ]);

        $payment = $this->checkoutService->createWalletChargePayment(
            $request->user(),
            (float) $validated['amount']
        );

        // Callback URL for frontend
        $callbackUrl = route('api.wallet.callback', [
            'payment' => $payment->id,
            'transaction_id' => $payment->transaction_id,
        ]);

        return response()->json([
            'ok' => true,
            'payment_id' => $payment->id,
            'amount' => (float) $payment->amount,
            'transaction_id' => $payment->transaction_id,
            'gateway_url' => $callbackUrl,
            'message' => 'درگاه پرداخت آماده است.',
        ]);
    }

    /**
     * Complete wallet top-up callback.
     */
    public function callback(Request $request): JsonResponse
    {
        $request->validate([
            'payment' => ['required', 'integer'],
            'transaction_id' => ['required', 'string'],
        ]);

        $payment = Payment::findOrFail($request->integer('payment'));

        if ($payment->user_id !== $request->user()->id) {
            return response()->json([
                'ok' => false,
                'error' => 'دسترسی غیرمجاز.',
            ], 403);
        }

        if ($payment->status === PaymentStatus::Paid) {
            return response()->json([
                'ok' => true,
                'message' => 'این تراکنش قبلاً با موفقیت انجام شده است.',
                'balance' => (float) $request->user()->wallet->balance,
            ]);
        }

        try {
            $this->checkoutService->completeWalletCharge(
                $payment,
                $request->string('transaction_id')->toString()
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'کیف پول شما با موفقیت شارژ شد.',
            'balance' => (float) $request->user()->fresh()->wallet->balance,
        ]);
    }
}

