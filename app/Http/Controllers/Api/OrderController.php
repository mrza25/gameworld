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
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderController extends Controller
{
    public function __construct(
        private CheckoutService $checkoutService
    ) {}

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
    public function show(Request $request, int|string $id): JsonResponse
    {
        $cleanId = (int) ltrim((string) $id, '#');
        $query = $request->user()->is_admin
            ? Order::query()
            : $request->user()->orders();

        $order = $query
            ->with(['items.game', 'items.gameVariant.platform'])
            ->findOrFail($cleanId);

        $orderData = $this->transformOrder($order);

        return response()->json([
            'ok' => true,
            'data' => $orderData,
            'order' => $orderData,
        ]);
    }

    /**
     * Checkout an order with either wallet balance or payment gateway.
     *
     * When no console is sent it is inferred from the selected variants, and
     * when no method is sent the wallet is used if its balance covers the total.
     */
    public function checkout(Request $request): JsonResponse
    {
        if (! $request->has('method') && $request->has('payment_method')) {
            $request->merge(['method' => $request->input('payment_method')]);
        }
        if (! $request->has('method') && $request->has('paymentMethod')) {
            $request->merge(['method' => $request->input('paymentMethod')]);
        }
        if (! $request->has('console') && $request->has('platform')) {
            $request->merge(['console' => $request->input('platform')]);
        }
        if (! $request->has('console') && $request->has('console_type')) {
            $request->merge(['console' => $request->input('console_type')]);
        }
        if ($request->has('game_ids') && ! $request->has('games')) {
            $request->merge(['games' => $request->input('game_ids')]);
        }
        if ($request->has('gameIds') && ! $request->has('games')) {
            $request->merge(['games' => $request->input('gameIds')]);
        }

        $validated = $request->validate([
            'console' => ['nullable', 'string'],
            'console_note' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', 'string', 'in:wallet,gateway'],
            'variants' => ['nullable', 'array', 'min:1'],
            'variants.*' => ['integer', 'exists:game_variants,id'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1'],
            'games' => ['nullable', 'array', 'min:1'],
            'games.*' => ['nullable'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ], [
            'method.in' => 'روش پرداخت باید کیف پول یا درگاه بانکی باشد.',
            'items.*.quantity.max' => 'هر بازی فقط یک بار روی کنسول نصب می‌شود؛ تعداد باید ۱ باشد.',
        ]);

        if (! empty($validated['coupon_code'])) {
            throw ValidationException::withMessages([
                'coupon_code' => 'کد تخفیف معتبر نیست.',
            ]);
        }

        // Resolve variant IDs (supports variants[], items[], or games[] slugs/IDs)
        $rawVariantIds = $validated['variants'] ?? [];
        $gameSlugs = $validated['games'] ?? [];

        if ($request->has('items') && is_array($request->input('items'))) {
            foreach ($request->input('items') as $item) {
                if (is_numeric($item)) {
                    $rawVariantIds[] = (int) $item;
                } elseif (is_string($item)) {
                    $gameSlugs[] = $item;
                } elseif (is_array($item)) {
                    if (! empty($item['variantId'])) {
                        $rawVariantIds[] = (int) $item['variantId'];
                    } elseif (! empty($item['variant_id'])) {
                        $rawVariantIds[] = (int) $item['variant_id'];
                    } elseif (! empty($item['id'])) {
                        $rawVariantIds[] = (int) $item['id'];
                    }

                    if (! empty($item['gameId'])) {
                        $gameSlugs[] = (string) $item['gameId'];
                    } elseif (! empty($item['game_id'])) {
                        $gameSlugs[] = (string) $item['game_id'];
                    } elseif (! empty($item['slug'])) {
                        $gameSlugs[] = (string) $item['slug'];
                    } elseif (! empty($item['game']['id'])) {
                        $gameSlugs[] = (string) $item['game']['id'];
                    } elseif (! empty($item['game']['slug'])) {
                        $gameSlugs[] = (string) $item['game']['slug'];
                    }
                }
            }
        }

        $platform = $this->resolveCheckoutPlatform($validated['console'] ?? null, $rawVariantIds);

        if (! $platform) {
            return response()->json([
                'ok' => false,
                'error' => empty($validated['console'])
                    ? 'انتخاب کنسول الزامی است؛ همهٔ بازی‌های سبد باید برای یک کنسول باشند.'
                    : 'کنسول انتخاب شده معتبر نیست.',
            ], 422);
        }

        if (! empty($gameSlugs)) {
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
        $requestedVariants = GameVariant::query()
            ->whereIn('id', array_unique($rawVariantIds))
            ->get(['id', 'game_id', 'platform_id']);

        $platformVariantIdsByGame = GameVariant::query()
            ->whereIn('game_id', $requestedVariants->pluck('game_id')->unique())
            ->where('platform_id', $platform->id)
            ->where('is_available', true)
            ->pluck('id', 'game_id');

        $variantIds = [];
        foreach ($requestedVariants as $v) {
            if ($v->platform_id === $platform->id || $platform->slug === 'other') {
                $variantIds[] = $v->id;
            } else {
                $variantIds[] = $platformVariantIdsByGame[$v->game_id] ?? $v->id;
            }
        }
        $variantIds = array_values(array_unique($variantIds));

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
            if (! $isMatchingPlatform) {
                return response()->json([
                    'ok' => false,
                    'error' => "نسخه انتخابی با کنسول {$platform->name} مطابقت ندارد.",
                ], 422);
            }
        }

        $totalCents = $variants->sum(fn (GameVariant $v) => (int) round((float) $v->price * 100));
        $total = $totalCents / 100;

        $validated['method'] ??= (float) ($request->user()->wallet?->balance ?? 0) >= $total ? 'wallet' : 'gateway';

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

                $orderData = $this->transformOrder($order->fresh(['items.game', 'items.gameVariant.platform']));
                $message = 'پرداخت با موفقیت انجام شد و سفارش شما ثبت گردید.';

                return response()->json([
                    'ok' => true,
                    'method' => 'wallet',
                    'order_id' => $order->id,
                    'id' => $order->id,
                    'order' => $orderData,
                    'data' => [
                        'method' => 'wallet',
                        'payment_method' => 'wallet',
                        'status' => 'paid',
                        'order_id' => $order->id,
                        'amount' => (float) $order->total,
                        'redirect_url' => "/my/orders/{$order->id}",
                        'message' => $message,
                        'order' => $orderData,
                    ],
                    'message' => $message,
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

            $frontendUrl = config('app.frontend_url');
            $frontendSuccessUrl = "{$frontendUrl}/my/orders?payment=success";

            return response()->json([
                'ok' => true,
                'data' => [
                    'method' => 'gateway',
                    'payment_method' => 'gateway',
                    'status' => 'pending_payment',
                    'gateway_url' => $callbackUrl,
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                    'authority' => $payment->transaction_id,
                    'amount' => (float) $payment->amount,
                    // The order is only created after the gateway confirms payment.
                    'order_id' => null,
                ],
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

        if ($payment->status === PaymentStatus::Paid && $payment->order_id && $payment->matchesTransaction($request->string('transaction_id')->toString())) {
            $order = Order::with(['items.game', 'items.gameVariant.platform'])->find($payment->order_id);
            $redirectUrl = "{$frontendUrl}/my/orders?payment=success&order_id={$payment->order_id}";

            if (! $request->wantsJson()) {
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

            if (! $request->wantsJson()) {
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
            $redirectUrl = "{$frontendUrl}/my/orders?payment=failed&error=".urlencode($e->getMessage());

            if (! $request->wantsJson()) {
                return redirect($redirectUrl);
            }

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'redirect_url' => $redirectUrl,
            ], 422);
        }
    }

    /**
     * The checkout console: the one requested, or else the single console that
     * all selected variants belong to.
     *
     * @param  array<int, int>  $variantIds
     */
    private function resolveCheckoutPlatform(?string $console, array $variantIds): ?Platform
    {
        if ($console !== null && $console !== '') {
            return Platform::where('slug', Str::slug($console))
                ->orWhere('name', $console)
                ->first();
        }

        $platformIds = GameVariant::query()
            ->whereIn('id', array_unique($variantIds))
            ->distinct()
            ->pluck('platform_id');

        return $platformIds->count() === 1 ? Platform::find($platformIds->first()) : null;
    }

    private function transformOrder(Order $order): array
    {
        return $order->toApiArray();
    }
}
