<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Platform;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    /**
     * Admin Dashboard statistics and recent orders.
     */
    public function dashboard(): JsonResponse
    {
        $stats = [
            'games' => Game::count(),
            'orders' => Order::count(),
            'users' => User::count(),
            'pending_orders' => Order::where('status', OrderStatus::Pending)->count(),
            'processing_orders' => Order::where('status', OrderStatus::Processing)->count(),
            'ready_orders' => Order::where('status', OrderStatus::Ready)->count(),
            'completed_orders' => Order::where('status', OrderStatus::Completed)->count(),
        ];

        $recentOrders = Order::with(['user', 'items.game'])
            ->latest()
            ->take(6)
            ->get();

        return response()->json([
            'ok' => true,
            'stats' => $stats,
            'recentOrders' => $recentOrders,
        ]);
    }

    /**
     * List all orders for administrative panel.
     */
    public function orders(Request $request): JsonResponse
    {
        $query = Order::with(['user', 'items.game', 'items.gameVariant.platform', 'payments'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phonenumber', 'like', "%{$search}%");
            });
        }

        $orders = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'ok' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Get single order details for admin.
     */
    public function orderDetail(int $id): JsonResponse
    {
        $order = Order::with(['user', 'items.game', 'items.gameVariant.platform', 'payments'])
            ->findOrFail($id);

        return response()->json([
            'ok' => true,
            'data' => $order,
        ]);
    }

    /**
     * Update order operational status.
     */
    public function updateOrderStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string'],
        ]);

        $order = Order::findOrFail($id);

        // Map Persian status strings if sent from frontend
        $statusKey = $this->resolveStatusKey($validated['status']);

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
        $paymentKey = $this->resolvePaymentStatusKey($validated['payment_status']);

        $order->update(['payment_status' => $paymentKey]);

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
        $item = $order->items()->findOrFail($itemId);

        $installKey = $this->resolveInstallStatusKey($validated['installation_status']);

        $oldOrderStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;

        $item->update([
            'installation_status' => $installKey,
            'installed_at' => $installKey === 'installed' ? now() : null,
        ]);

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
     * List users with search and pagination for admin.
     */
    public function users(Request $request): JsonResponse
    {
        $query = User::with('wallet')->latest();

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where('name', 'like', "%{$q}%")
                  ->orWhere('phonenumber', 'like', "%{$q}%");
        }

        $users = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'ok' => true,
            'data' => $users,
        ]);
    }

    private function resolveStatusKey(string $status): string
    {
        return match ($status) {
            'در حال بررسی', 'pending' => 'pending',
            'تایید شده', 'confirmed' => 'confirmed',
            'در حال پردازش', 'processing' => 'processing',
            'آماده تحویل', 'ready' => 'ready',
            'تکمیل شده', 'completed' => 'completed',
            'لغو شده', 'cancelled' => 'cancelled',
            default => 'pending',
        };
    }

    private function resolvePaymentStatusKey(string $status): string
    {
        return match ($status) {
            'پرداخت شده', 'paid' => 'paid',
            'در انتظار پرداخت', 'unpaid', 'pending' => 'unpaid',
            'پرداخت ناقص', 'partial' => 'partial',
            default => 'unpaid',
        };
    }

    private function resolveInstallStatusKey(string $status): string
    {
        return match ($status) {
            'نصب شده', 'installed' => 'installed',
            'در حال نصب', 'installing' => 'installing',
            default => 'pending',
        };
    }
}

