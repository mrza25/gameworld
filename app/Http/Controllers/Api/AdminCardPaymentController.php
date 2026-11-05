<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CardPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Reviewing card-to-card receipts. A receipt photo alone is easy to fake, so
 * approving requires the admin to state they saw the money in the account.
 */
class AdminCardPaymentController extends Controller
{
    public function __construct(
        private CardPaymentService $cardPaymentService
    ) {}

    public function approve(Request $request, int|string $id): JsonResponse
    {
        $this->ensureDepositWasSeen($request);

        $order = Order::findOrFail(Order::idFromReference($id));

        try {
            $order = $this->cardPaymentService->approve($order, $request->user());
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return $this->orderResponse($order, 'پرداخت تأیید شد و سفارش وارد روند نصب شد.');
    }

    public function reject(Request $request, int|string $id): JsonResponse
    {
        $reason = $this->rejectionReason($request);
        $order = Order::findOrFail(Order::idFromReference($id));

        try {
            $order = $this->cardPaymentService->reject($order, $request->user(), $reason);
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return $this->orderResponse($order, 'رسید رد شد و به مشتری اطلاع داده شد.');
    }

    /**
     * Wallet top-ups paid by card-to-card, newest first. `status=in_review` gives
     * the ones waiting for an admin.
     */
    public function walletCharges(Request $request): JsonResponse
    {
        $query = Payment::query()->cardWalletCharges()->with(['user', 'reviewer'])->latest()->orderByDesc('id');

        if ($request->input('status') === Payment::REVIEW_IN_REVIEW) {
            $query->inReview();
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        $charges = $query->paginate(min(max($request->integer('per_page', 15), 1), 100))->withQueryString();
        $duplicates = Payment::duplicateReceipts($charges->getCollection());

        return $this->paginatedResponse(
            $charges,
            $charges->getCollection()->map(fn (Payment $charge) => $this->walletChargeArray($charge, $duplicates[$charge->id]))
        );
    }

    public function approveWalletCharge(Request $request, int $id): JsonResponse
    {
        $this->ensureDepositWasSeen($request);

        $charge = Payment::query()->cardWalletCharges()->findOrFail($id);

        try {
            $charge = $this->cardPaymentService->approveWalletCharge($charge, $request->user());
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return $this->walletChargeResponse($charge, 'پرداخت تأیید شد و کیف پول مشتری شارژ شد.');
    }

    public function rejectWalletCharge(Request $request, int $id): JsonResponse
    {
        $reason = $this->rejectionReason($request);
        $charge = Payment::query()->cardWalletCharges()->findOrFail($id);

        try {
            $charge = $this->cardPaymentService->rejectWalletCharge($charge, $request->user(), $reason);
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return $this->walletChargeResponse($charge, 'رسید رد شد و به مشتری اطلاع داده شد.');
    }

    private function ensureDepositWasSeen(Request $request): void
    {
        $request->validate([
            'deposit_confirmed' => ['accepted'],
        ], [
            'deposit_confirmed.accepted' => 'فقط بعد از دیدن واریز در حساب بانکی می‌توانید تأیید کنید. این مورد را تأیید کنید.',
        ]);
    }

    private function rejectionReason(Request $request): string
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'دلیل رد رسید را بنویسید؛ این متن به مشتری نشان داده می‌شود.',
            'reason.max' => 'دلیل رد رسید حداکثر ۵۰۰ کاراکتر است.',
        ]);

        return trim($validated['reason']);
    }

    private function failure(RuntimeException $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => $this->publicErrorMessage($e),
        ], 422);
    }

    private function orderResponse(Order $order, string $message): JsonResponse
    {
        $order = $order->fresh(['user', 'items.game', 'items.gameVariant.platform', 'items.selectedOption', 'payments.reviewer']);
        $orderData = $order->toApiArray() + ['receipts' => $order->receiptHistoryArray()];

        return response()->json([
            'ok' => true,
            'message' => $message,
            'data' => $orderData,
            'order' => $orderData,
        ]);
    }

    private function walletChargeResponse(Payment $charge, string $message): JsonResponse
    {
        $charge = $charge->fresh(['user', 'reviewer']);
        $duplicates = Payment::duplicateReceipts(collect([$charge]));

        return response()->json([
            'ok' => true,
            'message' => $message,
            'data' => $this->walletChargeArray($charge, $duplicates[$charge->id]),
        ]);
    }

    /**
     * @param  array{duplicate_order_ids: list<int>, duplicate_wallet_charge_ids: list<int>}  $duplicates
     * @return array<string, mixed>
     */
    private function walletChargeArray(Payment $charge, array $duplicates): array
    {
        return $charge->toWalletChargeArray() + $duplicates + [
            'user' => $charge->user ? [
                'id' => $charge->user->id,
                'name' => $charge->user->name,
                'phone' => $charge->user->phonenumber,
                'email' => $charge->user->email,
            ] : null,
            'reviewed_by' => $charge->reviewer ? ['id' => $charge->reviewer->id, 'name' => $charge->reviewer->name] : null,
        ];
    }
}
