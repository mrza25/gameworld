<?php

namespace App\Notifications;

use App\Models\OrderItem;

class InstallationStatusUpdated extends UserNotification
{
    public function __construct(OrderItem $item)
    {
        $game = $item->game?->title ?? 'بازی';

        $message = match ($item->installation_status) {
            'installing' => "نصب «{$game}» شروع شد.",
            'installed' => "«{$game}» نصب شد.",
            default => "«{$game}» در انتظار نصب است.",
        };

        $this->compose(
            'installation_status',
            "وضعیت نصب در سفارش #{$item->order_id}",
            $message,
            "/my/orders/{$item->order_id}",
            [
                'order_id' => $item->order_id,
                'item_id' => $item->id,
                'game_id' => $item->game_id,
                'installation_status' => $item->installation_status,
            ]
        );
    }
}
