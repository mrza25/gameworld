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
                'date' => \App\Helpers\Jalali::fromDateTime($t->created_at, 'Y/m/d H:i'),
                'created_at' => $t->created_at,
            ]);

        return response()->json([
            'ok' => true,
            'balance' => (float) $wallet->balance,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Get paginated wallet transactions.
     */
    public function transactions(Request $request): JsonResponse
    {
        $wallet = $this->walletService->getOrCreateWallet($request->user());

        $transactions = $wallet->transactions()
            ->latest()
            ->paginate($request->integer('per_page', 15));

        $transactions->getCollection()->transform(fn ($t) => [
            'id' => $t->id,
            'type' => $t->type,
            'amount' => (float) $t->amount,
            'balanceAfter' => (float) $t->balance_after,
            'reference' => $t->reference,
            'description' => $t->description,
            'date' => \App\Helpers\Jalali::fromDateTime($t->created_at, 'Y/m/d H:i'),
            'created_at' => $t->created_at,
        ]);

        return response()->json([
            'ok' => true,
            'data' => $transactions,
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

        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $callbackUrl = route('api.wallet.callback', [
            'payment' => $payment->id,
            'transaction_id' => $payment->transaction_id,
        ]);

        return response()->json([
            'ok' => true,
            'payment_id' => $payment->id,
            'amount' => (float) $payment->amount,
            'transaction_id' => $payment->transaction_id,
            'action_url' => $callbackUrl,
            'gateway_url' => $callbackUrl,
            'callback_url' => $callbackUrl,
            'frontend_redirect_url' => "{$frontendUrl}/my/wallet?payment=success",
            'redirect_on_success' => '/my/wallet?payment=success',
            'redirect_on_failed' => '/my/wallet?payment=failed',
            'params' => [
                'payment' => $payment->id,
                'transaction_id' => $payment->transaction_id,
            ],
            'message' => 'درگاه پرداخت آماده است.',
        ]);
    }

    /**
     * Complete wallet top-up callback.
     */
    public function callback(Request $request)
    {
        $request->validate([
            'payment' => ['required', 'integer'],
            'transaction_id' => ['required', 'string'],
        ]);

        $payment = Payment::findOrFail($request->integer('payment'));
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');

        if ($payment->user_id !== $request->user()->id) {
            if (!$request->wantsJson()) {
                return redirect("{$frontendUrl}/my/wallet?payment=failed&error=unauthorized");
            }
            return response()->json([
                'ok' => false,
                'error' => 'دسترسی غیرمجاز.',
            ], 403);
        }

        if ($payment->status === PaymentStatus::Paid) {
            $redirectUrl = "{$frontendUrl}/my/wallet?payment=success";
            if (!$request->wantsJson()) {
                return redirect($redirectUrl);
            }
            return response()->json([
                'ok' => true,
                'message' => 'این تراکنش قبلاً با موفقیت انجام شده است.',
                'balance' => (float) $request->user()->wallet->balance,
                'redirect_url' => $redirectUrl,
            ]);
        }

        try {
            $this->checkoutService->completeWalletCharge(
                $payment,
                $request->string('transaction_id')->toString()
            );
        } catch (RuntimeException $e) {
            $redirectUrl = "{$frontendUrl}/my/wallet?payment=failed&error=" . urlencode($e->getMessage());
            if (!$request->wantsJson()) {
                return redirect($redirectUrl);
            }
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'redirect_url' => $redirectUrl,
            ], 422);
        }

        $balance = (float) $request->user()->fresh()->wallet->balance;
        $redirectUrl = "{$frontendUrl}/my/wallet?payment=success&balance={$balance}";

        if (!$request->wantsJson()) {
            return redirect($redirectUrl);
        }

        return response()->json([
            'ok' => true,
            'message' => 'کیف پول شما با موفقیت شارژ شد.',
            'balance' => $balance,
            'redirect_url' => $redirectUrl,
        ]);
    }
}

