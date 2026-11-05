<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Notifications\InstallationStatusUpdated;
use App\Notifications\OrderStatusUpdated;
use App\Notifications\PaymentStatusUpdated;
use App\OrderStatus;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function index()
    {
        $orders = Order::with([
            'user',
            'items.game',
            'items.gameVariant.platform',
            'payments',
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
            'items.gameVariant.platform',
            'payments',
        ]);

        return view('admin.orders.show', compact('order'));
    }

    public function updateStatus(
        Request $request,
        Order $order
    ) {
        if ($order->status === OrderStatus::Cancelled) {
            return back()->with('error', 'Cancelled orders have been refunded and cannot be changed.');
        }

        if ($order->isAwaitingPayment()) {
            return back()->with('error', 'This order is waiting for its payment to be confirmed.');
        }

        $validated = $request->validate([
            'status' => [
                'required',
                'in:pending,processing,completed',
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
        if ($order->status === OrderStatus::Cancelled) {
            return back()->with('error', 'Cancelled orders have been refunded and cannot be changed.');
        }

        if ($order->isAwaitingPayment()) {
            return back()->with('error', 'This order is waiting for its payment to be confirmed.');
        }

        $validated = $request->validate([
            'payment_status' => [
                'required',
                'in:unpaid,partial,paid',
            ],
        ]);

        $oldPaymentStatus = $order->payment_status;

        $order->update([
            'payment_status' => $validated['payment_status'],
        ]);

        if ($order->payment_status !== $oldPaymentStatus) {
            $order->user->notify(
                new PaymentStatusUpdated($order)
            );
        }

        return back()->with(
            'success',
            'Payment status updated successfully.'
        );
    }

    public function updateItemInstallationStatus(
        Request $request,
        Order $order
    ) {
        if ($order->status === OrderStatus::Cancelled) {
            return back()->with('error', 'Cancelled orders have been refunded and cannot be changed.');
        }

        if ($order->isAwaitingPayment()) {
            return back()->with('error', 'This order is waiting for its payment to be confirmed.');
        }

        $validated = $request->validate([
            'installation_status' => [
                'required',
                'in:pending,installing,installed',
            ],
        ]);

        $item = $order->items()
            ->findOrFail($request->item_id);

        $oldOrderStatus = $order->status->value;
        $oldInstallationStatus = $item->installation_status;

        $item->update([
            'installation_status' => $validated['installation_status'],
            'installed_at' => $validated['installation_status'] === 'installed'
                ? now()
                : null,
        ]);

        if ($item->installation_status !== $oldInstallationStatus) {
            $order->user->notify(
                new InstallationStatusUpdated($item->load('game'))
            );
        }

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
