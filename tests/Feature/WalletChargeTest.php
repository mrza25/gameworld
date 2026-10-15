<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletChargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_initiate_wallet_charge(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('wallet.charge'), [
            'amount' => 100000,
        ]);

        $payment = Payment::where('user_id', $user->id)->first();

        $this->assertNotNull($payment);
        $this->assertEquals(100000, $payment->amount);
        $this->assertEquals(PaymentStatus::Pending, $payment->status);

        $response->assertRedirect(route('wallet.callback', [
            'payment' => $payment->id,
            'transaction_id' => $payment->transaction_id,
        ]));
    }

    public function test_invalid_charge_amount_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('wallet.charge'), [
            'amount' => 1000, // less than min 50000
        ]);

        $response->assertSessionHasErrors(['amount']);
        $this->assertDatabaseEmpty('payments');
    }

    public function test_verified_charge_callback_credits_wallet_atomically(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet()->create(['balance' => 0]);

        $response = $this->actingAs($user)->post(route('wallet.charge'), [
            'amount' => 250000,
        ]);

        $payment = Payment::where('user_id', $user->id)->first();

        $callbackResponse = $this->actingAs($user)->get(route('wallet.callback', [
            'payment' => $payment->id,
            'transaction_id' => $payment->transaction_id,
        ]));

        $callbackResponse->assertRedirect(route('wallet.index'));

        $wallet->refresh();
        $this->assertEquals(250000, $wallet->balance);

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Paid, $payment->status);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 250000,
            'balance_after' => 250000,
        ]);
    }
}

