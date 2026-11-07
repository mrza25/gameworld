<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CouponController extends Controller
{
    /**
     * Preview a coupon on the cart page. Checkout checks it again with the real
     * prices, so the amount sent here decides nothing.
     */
    public function check(Request $request, CouponService $couponService): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'min:1'],
        ], [
            'code.required' => 'کد تخفیف را وارد کنید.',
            'amount.required' => 'مبلغ سبد خرید لازم است.',
        ]);

        try {
            $quote = $couponService->quote($validated['code'], $request->user(), (float) $validated['amount']);
        } catch (RuntimeException $e) {
            $error = $this->publicErrorMessage($e);

            return response()->json([
                'ok' => false,
                'error' => $error,
                'errors' => ['code' => [$error]],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'code' => $quote['coupon']->code,
                'type' => $quote['coupon']->type->value,
                'value' => (float) $quote['coupon']->value,
                'amount' => (float) $validated['amount'],
                'discount' => $quote['discount'],
                'payable' => $quote['payable'],
            ],
        ]);
    }
}
