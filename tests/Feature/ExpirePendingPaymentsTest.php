<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\PaymentMethod;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpirePendingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function createPayment(User $user, PaymentStatus $status): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'amount' => 100000,
            'method' => PaymentMethod::Gateway,
            'status' => $status,
        ]);
    }

    public function test_only_stale_pending_payments_are_marked_failed(): void
    {
        $user = User::factory()->create();

        $this->travel(-2)->hours();
        $stalePending = $this->createPayment($user, PaymentStatus::Pending);
        $stalePaid = $this->createPayment($user, PaymentStatus::Paid);
        $this->travelBack();

        $freshPending = $this->createPayment($user, PaymentStatus::Pending);

        $this->artisan('payments:expire-pending', ['--minutes' => 60])->assertSuccessful();

        $this->assertEquals(PaymentStatus::Failed, $stalePending->fresh()->status);
        $this->assertEquals(PaymentStatus::Paid, $stalePaid->fresh()->status);
        $this->assertEquals(PaymentStatus::Pending, $freshPending->fresh()->status);
    }
}
