<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\PaymentStatus;
use Illuminate\Console\Command;

class ExpirePendingPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:expire-pending {--minutes=60 : Mark payments pending for longer than this as failed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark abandoned pending gateway payments as failed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));

        $expiredCount = Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->update(['status' => PaymentStatus::Failed]);

        $this->info("Marked {$expiredCount} pending payment(s) as failed.");

        return self::SUCCESS;
    }
}
