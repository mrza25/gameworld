<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class OrderStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The status at the moment of the change. Captured here because the
     * queued order model is re-fetched (with its latest status) when handled.
     */
    public string $status;

    public function __construct(
        public Order $order
    ) {
        $this->status = $order->status->value;
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'status' => $this->status,
            'message' => "Order #{$this->order->id} is now {$this->status}.",
        ];
    }
}
