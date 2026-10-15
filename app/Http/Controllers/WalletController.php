<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\CheckoutService;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(
        Request $request,
        WalletService $walletService
    ) {
        $wallet = $walletService->getOrCreateWallet(
            $request->user()
        );

        $transactions = $wallet->transactions()
            ->latest()
            ->get();

        return view(
            'wallet.index',
            compact('wallet', 'transactions')
        );
    }

    public function charge(
        Request $request,
        CheckoutService $checkoutService
    ) {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:50000', 'max:50000000'],
        ]);

        $payment = $checkoutService->createWalletChargePayment(
            $request->user(),
            (float) $validated['amount']
        );

        return redirect()->route('wallet.callback', [
            'payment' => $payment->id,
            'transaction_id' => $payment->transaction_id,
        ]);
    }

    public function chargeCallback(
        Request $request,
        CheckoutService $checkoutService
    ) {
        $request->validate([
            'payment' => ['required', 'integer'],
            'transaction_id' => ['required', 'string'],
        ]);

        $payment = Payment::findOrFail($request->integer('payment'));

        if ($payment->user_id !== $request->user()->id) {
            abort(403);
        }

        try {
            $checkoutService->completeWalletCharge(
                $payment,
                $request->string('transaction_id')->toString()
            );
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('wallet.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wallet.index')
            ->with('success', 'کیف پول شما با موفقیت شارژ شد.');
    }
}
