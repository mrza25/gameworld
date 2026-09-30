<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use RuntimeException;

class CheckoutController extends Controller
{
    public function index(Request $request)
    {
        $checkout = $request->session()->get('checkout');

        if (!$checkout) {
            return redirect()
                ->route('orders.create')
                ->with(
                    'error',
                    'Please select your console and games first.'
                );
        }

        $games = Game::whereIn(
            'id',
            $checkout['games']
        )->get();

        if ($games->count() !== count($checkout['games'])) {
            $request->session()->forget('checkout');

            return redirect()
                ->route('orders.create')
                ->with(
                    'error',
                    'One or more selected games no longer exist.'
                );
        }

        $total = $games->sum('price');

        return view(
            'checkout.index',
            compact(
                'checkout',
                'games',
                'total'
            )
        );
    }

    public function payWithWallet(
        Request $request,
        CheckoutService $checkoutService
    ) {
        $checkout = $request->session()->get('checkout');

        if (!$checkout) {
            return redirect()
                ->route('orders.create')
                ->with(
                    'error',
                    'Your checkout session has expired.'
                );
        }

        $games = Game::whereIn(
            'id',
            $checkout['games']
        )->get();

        if ($games->count() !== count($checkout['games'])) {
            $request->session()->forget('checkout');

            return redirect()
                ->route('orders.create')
                ->with(
                    'error',
                    'One or more selected games no longer exist.'
                );
        }

        $total = (float) $games->sum('price');

        try {
            $order = $checkoutService->checkoutWithWallet(
                $request->user(),
                [
                    'console_type' => $checkout['console_type'],
                ],
                $checkout['games'],
                $total
            );
        } catch (RuntimeException $e) {
            return redirect()
                ->route('checkout.index')
                ->with('error', $e->getMessage());
        }

        $request->session()->forget('checkout');

        return redirect()
            ->route('orders.show', $order)
            ->with(
                'success',
                'Payment successful. Your order has been created.'
            );
    }
    public function payWithGateway(
    Request $request,
    CheckoutService $checkoutService
) {
    $checkout = $request->session()->get('checkout');

    if (!$checkout) {
        return redirect()
            ->route('orders.create')
            ->with(
                'error',
                'Your checkout session has expired.'
            );
    }

    $games = Game::whereIn(
        'id',
        $checkout['games']
    )->get();

    if ($games->count() !== count($checkout['games'])) {
        $request->session()->forget('checkout');

        return redirect()
            ->route('orders.create')
            ->with(
                'error',
                'One or more selected games no longer exist.'
            );
    }

    $total = (float) $games->sum('price');

    $payment = $checkoutService->createGatewayPayment(
        $request->user(),
        [
            'console_type' => $checkout['console_type'],
        ],
        $checkout['games'],
        $total
    );

    /*
     * فعلاً Gateway واقعی نداریم.
     *
     * برای تست، کاربر را به callback
     * شبیه‌سازی‌شده می‌فرستیم.
     */
    return redirect()->route(
        'payment.callback',
        [
            'payment' => $payment->id,
            'status' => 'success',
        ]
    );
}


public function gatewayCallback(
    Request $request,
    CheckoutService $checkoutService
) {
    $request->validate([
        'payment' => ['required', 'integer'],
        'status' => ['required', 'in:success,failed'],
    ]);

    $payment = \App\Models\Payment::findOrFail(
        $request->integer('payment')
    );

    if ($request->status === 'failed') {

        app(\App\Services\PaymentService::class)
            ->markAsFailed($payment);

        return redirect()
            ->route('checkout.index')
            ->with(
                'error',
                'Payment failed.'
            );
    }

    try {

        $transactionId = 'TEST-' . uniqid();

        $order = $checkoutService->completeGatewayCheckout(
            $payment,
            $transactionId
        );

    } catch (\RuntimeException $e) {

        return redirect()
            ->route('checkout.index')
            ->with(
                'error',
                $e->getMessage()
            );
    }

    $request->session()->forget('checkout');

    return redirect()
        ->route('orders.show', $order)
        ->with(
            'success',
            'Payment successful. Your order has been created.'
        );
}
}
