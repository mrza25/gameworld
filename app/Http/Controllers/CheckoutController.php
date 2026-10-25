<?php

namespace App\Http\Controllers;

use App\Models\GameVariant;
use App\Models\Payment;
use App\PaymentStatus;
use App\Services\CheckoutService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class CheckoutController extends Controller
{
    public function index(Request $request)
    {
        $checkout = $request->session()->get('checkout');

        if (! $checkout) {
            return redirect()
                ->route('orders.create')
                ->with('error', 'Please select your console and games first.');
        }

        try {
            $variants = $this->resolveVariants($checkout);
        } catch (RuntimeException $e) {
            $request->session()->forget('checkout');

            return redirect()
                ->route('orders.create')
                ->with('error', $e->getMessage());
        }

        $total = $this->calculateTotal($variants);

        return view('checkout.index', compact(
            'checkout',
            'variants',
            'total'
        ));
    }

    public function payWithWallet(
        Request $request,
        CheckoutService $checkoutService
    ) {
        $checkout = $request->session()->get('checkout');

        if (! $checkout) {
            return redirect()
                ->route('orders.create')
                ->with('error', 'Your checkout session has expired.');
        }

        try {
            $variants = $this->resolveVariants($checkout);
            $total = $this->calculateTotal($variants);

            $order = $checkoutService->checkoutWithWallet(
                $request->user(),
                [
                    'console_type' => $checkout['console_type'],
                ],
                $variants->pluck('id')->all(),
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

        if (! $checkout) {
            return redirect()
                ->route('orders.create')
                ->with('error', 'Your checkout session has expired.');
        }

        try {
            $variants = $this->resolveVariants($checkout);
            $total = $this->calculateTotal($variants);

            $payment = $checkoutService->createGatewayPayment(
                $request->user(),
                [
                    'console_type' => $checkout['console_type'],
                ],
                $variants->pluck('id')->all(),
                $total
            );
        } catch (RuntimeException $e) {
            return redirect()
                ->route('checkout.index')
                ->with('error', $e->getMessage());
        }

        // درگاه فعلی آزمایشی است.
        // تکمیل واقعی پرداخت باید توسط درگاه معتبر تأیید شود.
        return redirect()->route('payment.callback', [
            'payment' => $payment->id,
            'transaction_id' => $payment->transaction_id,
        ]);
    }

    public function gatewayCallback(
        Request $request,
        CheckoutService $checkoutService
    ) {
        $request->validate([
            'payment' => [
                'required',
                'integer',
            ],

            'transaction_id' => [
                'required',
                'string',
            ],
        ]);

        $payment = Payment::findOrFail(
            $request->integer('payment')
        );

        if ($payment->user_id !== $request->user()->id) {
            abort(403);
        }

        if (
            $payment->status === PaymentStatus::Paid
            && $payment->order_id
        ) {
            return redirect()
                ->route('orders.show', $payment->order_id)
                ->with(
                    'info',
                    'This payment has already been completed.'
                );
        }

        try {
            $order = $checkoutService->completeGatewayCheckout(
                $payment,
                $request->string('transaction_id')->toString()
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

    /**
     * Load the selected variants and verify that they are
     * available and belong to the selected console platform.
     */
    private function resolveVariants(array $checkout): Collection
    {
        $ids = $checkout['variants'] ?? [];
        $consoleType = $checkout['console_type'] ?? null;

        if (! is_array($ids) || count($ids) === 0 || ! $consoleType) {
            throw new RuntimeException(
                'Your checkout selection is invalid. Please select your games again.'
            );
        }

        $variants = GameVariant::query()
            ->with(['game', 'platform'])
            ->whereIn('id', $ids)
            ->where('is_available', true)
            ->whereHas('game')
            ->whereHas('platform')
            ->get();

        if ($variants->count() !== count(array_unique($ids))) {
            throw new RuntimeException(
                'One or more selected game versions are unavailable. Please select again.'
            );
        }

        $selectedPlatformSlug = Str::slug($consoleType);

        foreach ($variants as $variant) {
            if ($variant->platform->slug !== $selectedPlatformSlug) {
                throw new RuntimeException(
                    'A selected game version does not match your console.'
                );
            }
        }

        return $variants;
    }

    /**
     * Calculate the total using integer cents to avoid
     * floating-point accumulation errors.
     */
    private function calculateTotal(Collection $variants): float
    {
        $totalCents = $variants->sum(
            fn (GameVariant $variant) => (int) round((float) $variant->price * 100)
        );

        return $totalCents / 100;
    }
}
