<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\WalletTransaction;
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
    ) {}

    /**
     * Get wallet balance and transactions.
     */
    public function index(Request $request): JsonResponse
    {
        $wallet = $this->walletService->getOrCreateWallet($request->user());
        $balance = (float) $wallet->balance;

        $transactions = WalletTransaction::toApiCollection(
            $wallet->transactions()->latest()->orderByDesc('id')->get()
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'balance' => $balance,
                'transactions' => $transactions,
            ],
            'balance' => $balance,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Get paginated wallet transactions, optionally filtered by `type`:
     * credit | debit | deposit | payment | refund | adjustment.
     */
    public function transactions(Request $request): JsonResponse
    {
        $wallet = $this->walletService->getOrCreateWallet($request->user());

        $query = $wallet->transactions()->latest()->orderByDesc('id');

        if ($request->filled('type')) {
            $query->ofKind((string) $request->input('type'));
        }

        $transactions = $query->paginate(min(max($request->integer('per_page', 15), 1), 100))->withQueryString();
        $transactions->setCollection(WalletTransaction::toApiCollection($transactions->getCollection()));

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

        $frontendUrl = config('app.frontend_url');
        $callbackUrl = route('api.wallet.callback', [
            'payment' => $payment->id,
            'transaction_id' => $payment->transaction_id,
        ]);

        return response()->json([
            'ok' => true,
            'data' => [
                'method' => 'gateway',
                'gateway_url' => $callbackUrl,
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'amount' => (float) $payment->amount,
            ],
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
     *
     * This is where the payment gateway sends the customer's browser back, so it
     * does not require an API token: the payment is authenticated by verifying
     * its transaction with the gateway.
     */
    public function callback(Request $request)
    {
        $request->validate([
            'payment' => ['required', 'integer'],
            'transaction_id' => ['required', 'string'],
        ]);

        $payment = Payment::findOrFail($request->integer('payment'));
        $frontendUrl = config('app.frontend_url');

        if ($payment->status === PaymentStatus::Paid && $payment->matchesTransaction($request->string('transaction_id')->toString())) {
            $redirectUrl = "{$frontendUrl}/my/wallet?payment=success";
            if (! $request->wantsJson()) {
                return redirect($redirectUrl);
            }

            return response()->json([
                'ok' => true,
                'message' => 'این تراکنش قبلاً با موفقیت انجام شده است.',
                'balance' => (float) $payment->user->wallet?->balance,
                'redirect_url' => $redirectUrl,
            ]);
        }

        try {
            $this->checkoutService->completeWalletCharge(
                $payment,
                $request->string('transaction_id')->toString()
            );
        } catch (RuntimeException $e) {
            $redirectUrl = "{$frontendUrl}/my/wallet?payment=failed&error=".urlencode($e->getMessage());
            if (! $request->wantsJson()) {
                return redirect($redirectUrl);
            }

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'redirect_url' => $redirectUrl,
            ], 422);
        }

        $balance = (float) $payment->user->wallet()->value('balance');
        $redirectUrl = "{$frontendUrl}/my/wallet?payment=success&balance={$balance}";

        if (! $request->wantsJson()) {
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
