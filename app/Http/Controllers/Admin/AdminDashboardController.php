<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Order;
use App\UserStatus;
use App\OrderStatus;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'games' => Game::count(),
            'orders' => Order::count(),
            'pending_orders' => Order::where(
                'status',
                OrderStatus::Pending
            )->count(),
            'processing_orders' => Order::where(
                'status',
                OrderStatus::Processing
            )->count(),
            'completed_orders' => Order::where(
                'status',
                OrderStatus::Completed
            )->count(),
        ];

        $recentOrders = Order::with('user')
            ->latest()
            ->take(5)
            ->get();

        return view(
            'admin.dashboard',
            compact('stats', 'recentOrders')
        );
    }
}
