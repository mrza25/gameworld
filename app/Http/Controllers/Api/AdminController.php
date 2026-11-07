<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The admin dashboard and the wallet transaction log.
 */
class AdminController extends Controller
{
    /**
     * Admin Dashboard statistics and recent orders.
     */
    public function dashboard(): JsonResponse
    {
        $stats = $this->buildDashboardStats();
        $recentOrders = $this->recentOrders();

        return response()->json([
            'ok' => true,
            'data' => $this->dashboardWidgets($stats, $recentOrders),
            'stats' => $stats,
            'recentOrders' => $recentOrders,
            'total_orders' => $stats['total_orders'],
            'today_orders' => $stats['today_orders'],
            'total_users' => $stats['total_users'],
            'total_games' => $stats['total_games'],
        ]);
    }

    /**
     * Dedicated endpoint for admin dashboard widgets (dashstatus).
     */
    public function dashboardStats(): JsonResponse
    {
        $stats = $this->buildDashboardStats();

        return response()->json([
            'ok' => true,
            'data' => $this->dashboardWidgets($stats, $this->recentOrders()),
            'stats' => $stats,
            'total_orders' => $stats['total_orders'],
            'today_orders' => $stats['today_orders'],
            'total_users' => $stats['total_users'],
            'total_games' => $stats['total_games'],
        ]);
    }

    /**
     * Get all transactions for admin.
     */
    public function transactions(Request $request): JsonResponse
    {
        $query = WalletTransaction::with('wallet.user')->latest()->orderByDesc('id');

        if ($request->filled('user_id')) {
            $query->whereHas('wallet', fn ($wallet) => $wallet->where('user_id', $request->integer('user_id')));
        }

        $transactions = $query->paginate($this->perPage($request, 20))->withQueryString();

        return response()->json([
            'ok' => true,
            'data' => $transactions,
        ] + $this->paginationDetails($transactions));
    }

    /**
     * Shared statistics for the admin dashboard endpoints.
     *
     * @return array<string, int>
     */
    private function buildDashboardStats(): array
    {
        $totalOrders = Order::count();
        $totalUsers = User::count();
        $totalGames = Game::count();

        $statusCounts = Order::query()
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total_orders' => $totalOrders,
            'today_orders' => Order::whereDate('created_at', today())->count(),
            'total_users' => $totalUsers,
            'total_games' => $totalGames,
            'games' => $totalGames,
            'orders' => $totalOrders,
            'users' => $totalUsers,
            'awaiting_payment_orders' => (int) ($statusCounts[OrderStatus::AwaitingPayment->value] ?? 0),
            'receipts_in_review' => Order::query()->withReceiptInReview()->count(),
            'wallet_charges_in_review' => Payment::query()->cardWalletCharges()->inReview()->count(),
            'pending_orders' => (int) ($statusCounts[OrderStatus::Pending->value] ?? 0),
            'processing_orders' => (int) ($statusCounts[OrderStatus::Processing->value] ?? 0),
            'completed_orders' => (int) ($statusCounts[OrderStatus::Completed->value] ?? 0),
            'cancelled_orders' => (int) ($statusCounts[OrderStatus::Cancelled->value] ?? 0),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recentOrders(): Collection
    {
        return Order::with(['user', 'items.game', 'items.gameVariant.platform', 'items.selectedOption', 'payments'])
            ->latest()
            ->orderByDesc('id')
            ->take(6)
            ->get()
            ->map(fn (Order $order) => $order->toApiArray());
    }

    /**
     * Dashboard widgets in the shape the admin panel expects.
     *
     * @param  array<string, int>  $stats
     * @param  Collection<int, array<string, mixed>>  $recentOrders
     * @return array<string, mixed>
     */
    private function dashboardWidgets(array $stats, Collection $recentOrders): array
    {
        $recentUsers = User::with('wallet')
            ->latest()
            ->orderByDesc('id')
            ->take(5)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phonenumber,
                'email' => $user->email,
                'role' => $user->is_admin ? 'admin' : 'user',
                'wallet_balance' => (float) ($user->wallet?->balance ?? 0),
                'created_at' => $user->created_at?->toIso8601String(),
            ]);

        return [
            'totalUsers' => $stats['total_users'],
            'totalGames' => $stats['total_games'],
            'totalOrders' => $stats['total_orders'],
            'todayOrders' => $stats['today_orders'],
            'totalRevenue' => (float) Order::where('payment_status', PaymentStatus::Paid)->sum('total'),
            'awaitingPaymentOrders' => $stats['awaiting_payment_orders'],
            'receiptsInReview' => $stats['receipts_in_review'],
            'walletChargesInReview' => $stats['wallet_charges_in_review'],
            'pendingOrders' => $stats['pending_orders'],
            'processingOrders' => $stats['processing_orders'],
            'completedOrders' => $stats['completed_orders'],
            'cancelledOrders' => $stats['cancelled_orders'],
            'recentUsers' => $recentUsers,
            'recentOrders' => $recentOrders,
        ];
    }
}
