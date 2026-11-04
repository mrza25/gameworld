<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Models\Game;
use App\Models\Payment;
use App\PaymentMethod;
use App\PaymentStatus;
use App\Services\PaymentGateways\FakePaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The sandbox payment page that stands in for the bank while the fake gateway
 * is bound. The customer pays or cancels here and is then sent back to the
 * API callback with `status=OK` or `status=NOK`, like a real gateway.
 */
class FakeGatewayController extends Controller
{
    public function __construct(
        private PaymentGateway $paymentGateway
    ) {}

    public function show(Request $request, Payment $payment): View
    {
        $this->sandboxGateway($request, $payment);

        $gameIds = collect($payment->metadata['items'] ?? [])->pluck('game_id');

        return view('payments.fake-gateway', [
            'payment' => $payment,
            'isWalletCharge' => $payment->isWalletCharge(),
            'isPending' => $payment->status === PaymentStatus::Pending,
            'isPaid' => $payment->status === PaymentStatus::Paid,
            'gameTitles' => $gameIds->isEmpty() ? collect() : Game::whereIn('id', $gameIds)->pluck('title'),
        ]);
    }

    /**
     * Record the customer's choice and send them back to the API callback.
     * Once the payment is no longer pending, the choice is ignored and the
     * customer is simply returned with the payment's actual outcome.
     */
    public function submit(Request $request, Payment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'transaction_id' => ['required', 'string'],
            'result' => ['required', 'in:pay,cancel'],
        ]);

        $gateway = $this->sandboxGateway($request, $payment);

        if ($payment->status === PaymentStatus::Pending) {
            $approved = $validated['result'] === 'pay';
            $gateway->recordDecision($payment, $approved);
        } else {
            $approved = $payment->status === PaymentStatus::Paid;
        }

        return redirect()->to(route($payment->isWalletCharge() ? 'api.wallet.callback' : 'api.orders.callback', [
            'payment' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'status' => $approved ? 'OK' : 'NOK',
        ]));
    }

    /**
     * The page only exists while the fake gateway is bound, and only for a
     * gateway payment whose transaction id the visitor knows.
     */
    private function sandboxGateway(Request $request, Payment $payment): FakePaymentGateway
    {
        $gateway = $this->paymentGateway;

        if (
            ! $gateway instanceof FakePaymentGateway ||
            ! FakePaymentGateway::isEnabled() ||
            $payment->method !== PaymentMethod::Gateway ||
            ! $payment->matchesTransaction($request->string('transaction_id')->toString())
        ) {
            abort(404);
        }

        return $gateway;
    }
}
