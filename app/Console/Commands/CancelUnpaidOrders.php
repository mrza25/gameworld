<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\OrderStatus;
use App\Services\OrderCancellationService;
use Illuminate\Console\Command;

class CancelUnpaidOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:cancel-unpaid';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cancel card-to-card orders past their payment deadline, unless a receipt is under review';

    /**
     * Execute the console command.
     */
    public function handle(OrderCancellationService $orderCancellationService): int
    {
        $overdueOrders = Order::query()
            ->where('status', OrderStatus::AwaitingPayment)
            ->where('payment_due_at', '<', now())
            ->whereDoesntHave('payments', fn ($payments) => $payments->inReview())
            ->get();

        foreach ($overdueOrders as $order) {
            $orderCancellationService->cancel($order, byAdmin: true);
        }

        $this->info("Cancelled {$overdueOrders->count()} unpaid order(s).");

        return self::SUCCESS;
    }
}
