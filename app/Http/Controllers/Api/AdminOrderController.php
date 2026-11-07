<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\InstallationStatusUpdated;
use App\Notifications\OrderStatusUpdated;
use App\Notifications\PaymentStatusUpdated;
use App\OrderStatus;
use App\PaymentStatus;
use App\Services\OrderCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Managing customer orders.
 */
class AdminOrderController extends Controller
{
    public function __construct(
        private OrderCancellationService $orderCancellationService
    ) {}

    /**
     * List all orders for administrative panel.
     */
    public function orders(Request $request): JsonResponse
    {
        $query = Order::with(['user', 'items.game', 'items.gameVariant.platform', 'items.selectedOption', 'payments'])->latest()->orderByDesc('id');

        foreach (['statusRaw' => 'status', 'paymentStatusRaw' => 'payment_status', 'date_from' => 'from_date', 'date_to' => 'to_date'] as $alias => $field) {
            if (! $request->filled($field) && $request->filled($alias)) {
                $request->merge([$field => $request->input($alias)]);
            }
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $this->resolvePaymentStatusKey((string) $request->input('payment_status')));
        }

        if ($request->filled('console')) {
            $query->forConsole((string) $request->input('console'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->input('receipt_status') === 'in_review') {
            $query->withReceiptInReview();
        }

        if ($request->filled('status')) {
            $statusKey = $this->resolveStatusKey($request->status);
            $query->where('status', $statusKey);
        }

        if ($request->filled('payment_method')) {
            $pm = $request->payment_method;
            $query->whereHas('payments', function ($q) use ($pm) {
                $q->where('method', 'like', "%{$pm}%");
            });
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = (string) $request->search;
            $orderId = preg_match('/^(#|ORD-)?\d+$/i', trim($search)) ? Order::idFromReference($search) : null;
            $query->where(function ($q) use ($search, $orderId) {
                if ($orderId !== null) {
                    $q->where('id', $orderId);
                }
                $q->orWhereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('phonenumber', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        $orders = $query->paginate($this->perPage($request));
        $orders->getCollection()->transform(fn (Order $order) => $this->orderWithUser($order));

        $paginatorArray = $orders->toArray();
        $pagination = $this->paginationDetails($orders);
        $paginatorArray['meta'] = $pagination['meta'];

        return response()->json([
            'ok' => true,
            'data' => $paginatorArray,
            'orders' => $orders->items(),
        ] + $pagination);
    }

    /**
     * Get single order details for admin.
     */
    public function orderDetail(int|string $id): JsonResponse
    {
        $order = Order::with(['user', 'items.game', 'items.gameVariant.platform', 'items.selectedOption', 'payments.reviewer'])
            ->findOrFail(Order::idFromReference($id));

        $orderData = $this->orderWithUser($order) + ['receipts' => $order->receiptHistoryArray()];

        return response()->json([
            'ok' => true,
            'data' => $orderData,
            'order' => $orderData,
        ]);
    }

    /**
     * Unified update for order (status, payment_status, install_statuses).
     */
    public function updateOrder(Request $request, int|string $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail(Order::idFromReference($id));

        if (! $request->has('status') && $request->has('statusRaw')) {
            $request->merge(['status' => $request->input('statusRaw')]);
        }
        if (! $request->has('payment_status') && $request->has('paymentStatusRaw')) {
            $request->merge(['payment_status' => $request->input('paymentStatusRaw')]);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'payment_status' => ['nullable', 'string'],
            'install_statuses' => ['nullable', 'array'],
            'install_statuses.*.item_id' => ['nullable', 'integer'],
            'install_statuses.*.id' => ['nullable', 'integer'],
            'install_statuses.*.install_status' => ['nullable', 'string'],
            'install_statuses.*.status' => ['nullable', 'string'],
        ]);

        $this->ensureOrderIsNotCancelled($order);

        if (! empty($validated['status']) && $this->resolveStatusKey($validated['status']) === OrderStatus::Cancelled->value) {
            return $this->cancelOrderAsAdmin($order);
        }

        $this->ensureOrderIsPaidFor($order);

        if (! empty($validated['status'])) {
            $this->ensureStatusCanBeSet($this->resolveStatusKey($validated['status']));
        }

        $oldStatus = $order->status;

        if (! empty($validated['status'])) {
            $order->update(['status' => $this->resolveStatusKey($validated['status'])]);
        }

        if (! empty($validated['payment_status'])) {
            $oldPaymentStatus = $order->payment_status;
            $order->update(['payment_status' => $this->resolvePaymentStatusKey($validated['payment_status'])]);

            if ($order->payment_status !== $oldPaymentStatus) {
                $order->user?->notify(new PaymentStatusUpdated($order));
            }
        }

        if (! empty($validated['install_statuses']) && is_array($validated['install_statuses'])) {
            foreach ($validated['install_statuses'] as $itemStatus) {
                $itemId = $itemStatus['item_id'] ?? $itemStatus['id'] ?? null;
                $newInstallStatus = $itemStatus['install_status'] ?? $itemStatus['status'] ?? null;
                if ($itemId && $newInstallStatus) {
                    $item = $order->items()->find($itemId);
                    if ($item) {
                        $this->updateInstallationStatus($order, $item, $this->resolveInstallStatusKey($newInstallStatus));
                    }
                }
            }
            // The items were eager loaded before the changes above; sync from their new statuses.
            $order->load('items')->syncStatusFromItems();
        }

        $order->refresh();
        if ($order->status !== $oldStatus) {
            $order->user?->notify(new OrderStatusUpdated($order));
        }

        $orderData = $order->fresh(['user', 'items.game', 'items.gameVariant.platform', 'items.selectedOption', 'payments'])->toApiArray();

        return response()->json([
            'ok' => true,
            'message' => 'سفارش با موفقیت به‌روزرسانی شد.',
            'data' => $orderData,
            'order' => $orderData,
        ]);
    }

    /**
     * Update order operational status.
     */
    public function updateOrderStatus(Request $request, int|string $id): JsonResponse
    {
        if (! $request->has('status') && $request->has('statusRaw')) {
            $request->merge(['status' => $request->input('statusRaw')]);
        }

        $validated = $request->validate([
            'status' => ['required', 'string'],
        ]);

        $order = Order::findOrFail(Order::idFromReference($id));
        $this->ensureOrderIsNotCancelled($order);

        // Map Persian status strings if sent from frontend
        $statusKey = $this->resolveStatusKey($validated['status']);

        if ($statusKey === OrderStatus::Cancelled->value) {
            return $this->cancelOrderAsAdmin($order);
        }

        $this->ensureOrderIsPaidFor($order);
        $this->ensureStatusCanBeSet($statusKey);

        $oldStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;
        $order->update(['status' => $statusKey]);
        $order->refresh();

        $newStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;
        if ($oldStatus !== $newStatus) {
            $order->user?->notify(new OrderStatusUpdated($order));
        }

        return response()->json([
            'ok' => true,
            'message' => 'وضعیت سفارش با موفقیت به‌روزرسانی شد.',
            'status' => $newStatus,
        ]);
    }

    /**
     * Update order payment status.
     */
    public function updatePaymentStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'payment_status' => ['required', 'string'],
        ]);

        $order = Order::findOrFail($id);
        $this->ensureOrderIsNotCancelled($order);
        $this->ensureOrderIsPaidFor($order);
        $paymentKey = $this->resolvePaymentStatusKey($validated['payment_status']);
        $oldPaymentStatus = $order->payment_status;

        $order->update(['payment_status' => $paymentKey]);

        if ($order->payment_status !== $oldPaymentStatus) {
            $order->user?->notify(new PaymentStatusUpdated($order));
        }

        return response()->json([
            'ok' => true,
            'message' => 'وضعیت پرداخت با موفقیت به‌روزرسانی شد.',
            'payment_status' => $paymentKey,
        ]);
    }

    /**
     * Update installation status of a single item in an order.
     */
    public function updateItemInstallationStatus(Request $request, int $orderId, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'installation_status' => ['required', 'string'],
        ]);

        $order = Order::findOrFail($orderId);
        $this->ensureOrderIsNotCancelled($order);
        $this->ensureOrderIsPaidFor($order);
        $item = $order->items()->findOrFail($itemId);

        $installKey = $this->resolveInstallStatusKey($validated['installation_status']);

        $oldOrderStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;

        $this->updateInstallationStatus($order, $item, $installKey);

        $order->syncStatusFromItems();
        $order->refresh();

        $newOrderStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;
        if ($oldOrderStatus !== $newOrderStatus) {
            $order->user?->notify(new OrderStatusUpdated($order));
        }

        return response()->json([
            'ok' => true,
            'message' => 'وضعیت نصب بازی به‌روزرسانی شد.',
            'item' => $item->fresh(),
            'order_status' => $newOrderStatus,
        ]);
    }

    /**
     * Change one item's installation status and tell the customer about it.
     */
    private function updateInstallationStatus(Order $order, OrderItem $item, string $installKey): void
    {
        if ($item->installation_status === $installKey) {
            return;
        }

        $item->update([
            'installation_status' => $installKey,
            'installed_at' => $installKey === 'installed' ? now() : null,
        ]);

        $order->user?->notify(new InstallationStatusUpdated($item->load('game')));
    }

    /**
     * Order API shape plus the customer's contact details, for admin views.
     *
     * @return array<string, mixed>
     */
    private function orderWithUser(Order $order): array
    {
        return $order->toApiArray() + [
            'user' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'phone' => $order->user->phonenumber,
                'email' => $order->user->email,
            ] : null,
        ];
    }

    /**
     * A cancelled order has been refunded, so it must never become active again.
     */
    private function ensureOrderIsNotCancelled(Order $order): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => 'سفارش لغوشده قابل تغییر نیست.',
            ]);
        }
    }

    /**
     * An order waiting for its card-to-card payment can only be approved,
     * rejected or cancelled: see AdminCardPaymentController.
     */
    private function ensureOrderIsPaidFor(Order $order): void
    {
        if ($order->isAwaitingPayment()) {
            throw ValidationException::withMessages([
                'status' => 'پرداخت این سفارش هنوز تأیید نشده است. ابتدا رسید را بررسی کنید.',
            ]);
        }
    }

    private function ensureStatusCanBeSet(string $statusKey): void
    {
        if ($statusKey === OrderStatus::AwaitingPayment->value) {
            throw ValidationException::withMessages([
                'status' => 'این وضعیت را نمی‌توان دستی انتخاب کرد.',
            ]);
        }
    }

    private function cancelOrderAsAdmin(Order $order): JsonResponse
    {
        try {
            $order = $this->orderCancellationService->cancel($order, byAdmin: true);
        } catch (\RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'error' => $this->publicErrorMessage($e),
            ], 422);
        }

        $orderData = $order->toApiArray();

        return response()->json([
            'ok' => true,
            'message' => 'سفارش لغو شد و مبلغ آن به کیف پول مشتری بازگشت.',
            'status' => $order->status->value,
            'data' => $orderData,
            'order' => $orderData,
        ]);
    }

    private function resolveStatusKey(string $status): string
    {
        return match ($status) {
            'در انتظار تأیید پرداخت', 'awaiting_payment' => OrderStatus::AwaitingPayment->value,
            'در حال بررسی', 'pending' => OrderStatus::Pending->value,
            'در حال پردازش', 'تایید شده', 'processing', 'confirmed' => OrderStatus::Processing->value,
            'تکمیل شده', 'آماده تحویل', 'completed', 'ready' => OrderStatus::Completed->value,
            'لغو شده', 'cancelled', 'canceled' => OrderStatus::Cancelled->value,
            default => throw ValidationException::withMessages([
                'status' => 'وضعیت سفارش نامعتبر است.',
            ]),
        };
    }

    private function resolvePaymentStatusKey(string $status): string
    {
        return match ($status) {
            'پرداخت شده', 'paid' => PaymentStatus::Paid->value,
            'در انتظار پرداخت', 'unpaid', 'pending' => PaymentStatus::Unpaid->value,
            'پرداخت ناقص', 'partial' => PaymentStatus::Partial->value,
            'ناموفق', 'failed' => PaymentStatus::Failed->value,
            'مسترد شده', 'refunded' => PaymentStatus::Refunded->value,
            default => throw ValidationException::withMessages([
                'payment_status' => 'وضعیت پرداخت نامعتبر است.',
            ]),
        };
    }

    private function resolveInstallStatusKey(string $status): string
    {
        return match ($status) {
            'نصب شده', 'installed' => 'installed',
            'در حال نصب', 'installing' => 'installing',
            'در انتظار نصب', 'pending' => 'pending',
            default => throw ValidationException::withMessages([
                'installation_status' => 'وضعیت نصب نامعتبر است.',
            ]),
        };
    }
}
