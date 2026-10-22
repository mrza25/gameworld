<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Platform;
use App\PaymentStatus;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class OrderController extends Controller
{
    public function __construct(
        private CheckoutService $checkoutService
    ) {
    }

    /**
     * List current authenticated customer's orders.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->orders()
            ->with(['items.game', 'items.gameVariant.platform'])
            ->latest()
            ->get()
            ->map(fn (Order $order) => $this->transformOrder($order));

        return response()->json([
            'ok' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Get details of a single customer order.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $query = $request->user()->is_admin
            ? Order::query()
            : $request->user()->orders();

        $order = $query
            ->with(['items.game', 'items.gameVariant.platform'])
            ->findOrFail($id);

        return response()->json([
            'ok' => true,
            'data' => $this->transformOrder($order),
        ]);
    }

    /**
     * Checkout an order with either wallet balance or payment gateway.
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'console' => ['required', 'string'],
            'console_note' => ['nullable', 'string', 'max:255'],
            'method' => ['required', 'in:wallet,gateway'],
            'variants' => ['nullable', 'array', 'min:1'],
            'variants.*' => ['integer', 'exists:game_variants,id'],
            'items' => ['nullable', 'array', 'min:1'],
            'games' => ['nullable', 'array', 'min:1'],
            'games.*' => ['string'],
        ]);

        $consoleSlug = Str::slug($validated['console']);
        $platform = Platform::where('slug', $consoleSlug)
            ->orWhere('name', $validated['console'])
            ->first();

        if (!$platform) {
            return response()->json([
                'ok' => false,
                'error' => 'کنسول انتخاب شده معتبر نیست.',
            ], 422);
        }

        // Resolve variant IDs (supports variants[], items[], or games[] slugs/IDs)
        $rawVariantIds = $validated['variants'] ?? [];
        $gameSlugs = $validated['games'] ?? [];

        if ($request->has('items') && is_array($request->input('items'))) {
            foreach ($request->input('items') as $item) {
                if (is_numeric($item)) {
                    $rawVariantIds[] = (int) $item;
                } elseif (is_array($item)) {
                    if (!empty($item['variantId'])) $rawVariantIds[] = (int) $item['variantId'];
                    elseif (!empty($item['variant_id'])) $rawVariantIds[] = (int) $item['variant_id'];
                    elseif (!empty($item['id'])) $rawVariantIds[] = (int) $item['id'];
                    elseif (!empty($item['gameId'])) $gameSlugs[] = (string) $item['gameId'];
                    elseif (!empty($item['game_id'])) $gameSlugs[] = (string) $item['game_id'];
                    elseif (!empty($item['slug'])) $gameSlugs[] = (string) $item['slug'];
                }
            }
        }

        if (!empty($gameSlugs)) {
            $games = Game::whereIn('slug', $gameSlugs)
                ->orWhereIn('id', array_filter($gameSlugs, 'is_numeric'))
                ->pluck('id');

            $matchedVariants = GameVariant::whereIn('game_id', $games)
                ->where('platform_id', $platform->id)
                ->where('is_available', true)
                ->pluck('id')
                ->all();

            $rawVariantIds = array_merge($rawVariantIds, $matchedVariants);
        }

        // Smart Variant Resolution: if variant belongs to another platform, map to this platform
        $variantIds = [];
        foreach (array_unique($rawVariantIds) as $vId) {
            $v = GameVariant::find($vId);
            if (!$v) continue;

            if ($v->platform_id === $platform->id || $platform->slug === 'other') {
                $variantIds[] = $v->id;
            } else {
                $match = GameVariant::where('game_id', $v->game_id)
                    ->where('platform_id', $platform->id)
                    ->where('is_available', true)
                    ->first();
                $variantIds[] = $match ? $match->id : $v->id;
            }
        }

        if (empty($variantIds)) {
            return response()->json([
                'ok' => false,
                'error' => 'حداقل یک نسخه بازی معتبر باید انتخاب شود.',
            ], 422);
        }

        $variants = GameVariant::with('platform')
            ->whereIn('id', $variantIds)
            ->where('is_available', true)
            ->get();

        if ($variants->count() !== count(array_unique($variantIds))) {
            return response()->json([
                'ok' => false,
                'error' => 'یکی از بازی‌های انتخابی در دسترس نیست.',
            ], 422);
        }

        foreach ($variants as $variant) {
            $isMatchingPlatform = ($variant->platform_id === $platform->id) || ($platform->slug === 'other');
            if (!$isMatchingPlatform) {
                return response()->json([
                    'ok' => false,
                    'error' => "نسخه انتخابی با کنسول {$platform->name} مطابقت ندارد.",
                ], 422);
            }
        }

        $totalCents = $variants->sum(fn (GameVariant $v) => (int) round((float) $v->price * 100));
        $total = $totalCents / 100;

        // Wallet Checkout
        if ($validated['method'] === 'wallet') {
            try {
                $order = $this->checkoutService->checkoutWithWallet(
                    $request->user(),
                    [
                        'console_type' => $platform->name,
                        'console_note' => $validated['console_note'] ?? null,
                    ],
                    $variantIds,
                    $total
                );

                return response()->json([
                    'ok' => true,
                    'method' => 'wallet',
                    'order_id' => $order->id,
                    'order' => $this->transformOrder($order->fresh(['items.game', 'items.gameVariant.platform'])),
                    'message' => 'پرداخت با موفقیت انجام شد و سفارش شما ثبت گردید.',
                ], 201);
            } catch (RuntimeException $e) {
                return response()->json([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], 422);
            }
        }

        // Gateway Checkout
        try {
            $payment = $this->checkoutService->createGatewayPayment(
                $request->user(),
                [
                    'console_type' => $platform->name,
                    'console_note' => $validated['console_note'] ?? null,
                ],
                $variantIds,
                $total
            );

            $callbackUrl = route('api.orders.callback', [
                'payment' => $payment->id,
                'transaction_id' => $payment->transaction_id,
            ]);

            $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
            $frontendSuccessUrl = "{$frontendUrl}/my/orders?payment=success";
            $frontendFailedUrl = "{$frontendUrl}/my/orders?payment=failed";

            return response()->json([
                'ok' => true,
                'method' => 'gateway',
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'amount' => (float) $payment->amount,
                'action_url' => $callbackUrl,
                'gateway_url' => $callbackUrl,
                'callback_url' => $callbackUrl,
                'frontend_redirect_url' => $frontendSuccessUrl,
                'redirect_on_success' => '/my/orders?payment=success',
                'redirect_on_failed' => '/my/orders?payment=failed',
                'params' => [
                    'payment' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                ],
                'message' => 'انتقال به درگاه پرداخت...',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Complete gateway payment verification for an order.
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
                return redirect("{$frontendUrl}/my/orders?payment=failed&error=unauthorized");
            }
            return response()->json([
                'ok' => false,
                'error' => 'دسترسی غیرمجاز.',
            ], 403);
        }

        if ($payment->status === PaymentStatus::Paid && $payment->order_id) {
            $order = Order::with(['items.game', 'items.gameVariant.platform'])->find($payment->order_id);
            $redirectUrl = "{$frontendUrl}/my/orders?payment=success&order_id={$payment->order_id}";

            if (!$request->wantsJson()) {
                return redirect($redirectUrl);
            }

            return response()->json([
                'ok' => true,
                'order_id' => $payment->order_id,
                'order' => $order ? $this->transformOrder($order) : null,
                'redirect_url' => $redirectUrl,
                'message' => 'این سفارش قبلاً با موفقیت پرداخت شده است.',
            ]);
        }

        try {
            $order = $this->checkoutService->completeGatewayCheckout(
                $payment,
                $request->string('transaction_id')->toString()
            );

            $redirectUrl = "{$frontendUrl}/my/orders?payment=success&order_id={$order->id}";

            if (!$request->wantsJson()) {
                return redirect($redirectUrl);
            }

            return response()->json([
                'ok' => true,
                'order_id' => $order->id,
                'order' => $this->transformOrder($order->fresh(['items.game', 'items.gameVariant.platform'])),
                'redirect_url' => $redirectUrl,
                'message' => 'پرداخت تأیید شد و سفارش شما ثبت گردید.',
            ]);
        } catch (RuntimeException $e) {
            $redirectUrl = "{$frontendUrl}/my/orders?payment=failed&error=" . urlencode($e->getMessage());

            if (!$request->wantsJson()) {
                return redirect($redirectUrl);
            }

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'redirect_url' => $redirectUrl,
            ], 422);
        }
    }

    private function transformOrder(Order $order): array
    {
        return $order->toApiArray();
    }
}

