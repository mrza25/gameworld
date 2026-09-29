<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use App\Notifications\OrderStatusUpdated;


class AdminOrderController extends Controller
{
    public function index()
    {
        $orders = Order::with([
            'user',
            'items.game',
        ])
            ->latest()
            ->get();

        return view('admin.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $order->load([
            'user',
            'items.game',
        ]);

        return view('admin.orders.show', compact('order'));
    }

    public function updateStatus(
    Request $request,
    Order $order
) {
    $validated = $request->validate([
        'status' => [
            'required',
            'in:pending,confirmed,processing,ready,completed,cancelled',
        ],
    ]);

    $oldStatus = $order->status->value;

    $order->update([
        'status' => $validated['status'],
    ]);

    $order->refresh();

    if ($oldStatus !== $order->status->value) {
        $order->user->notify(
            new OrderStatusUpdated($order)
        );
    }

    return back()->with(
        'success',
        'Order status updated successfully.'
    );
}
    public function updatePaymentStatus(
        Request $request,
        Order $order
    ) {
        $validated = $request->validate([
            'payment_status' => [
                'required',
                'in:unpaid,partial,paid',
            ],
        ]);

        $order->update([
            'payment_status' => $validated['payment_status'],
        ]);

        return back()->with(
            'success',
            'Payment status updated successfully.'
        );
    }


    public function updateItemInstallationStatus(
    Request $request,
    Order $order
) {
    $validated = $request->validate([
        'installation_status' => [
            'required',
            'in:pending,installing,installed',
        ],
    ]);

    $item = $order->items()
        ->findOrFail($request->item_id);

    $oldOrderStatus = $order->status->value;

    $item->update([
        'installation_status' => $validated['installation_status'],
        'installed_at' => $validated['installation_status'] === 'installed'
            ? now()
            : null,
    ]);

    $order->syncStatusFromItems();

    $order->refresh();

    if ($oldOrderStatus !== $order->status->value) {
        $order->user->notify(
            new OrderStatusUpdated($order)
        );
    }

    return back()->with(
        'success',
        'Game installation status updated successfully.'
    );
}
}
