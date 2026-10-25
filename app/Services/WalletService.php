<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletService
{
    public function getOrCreateWallet(User $user): Wallet
    {
        return $user->wallet()->firstOrCreate([
            'user_id' => $user->id,
        ]);
    }

    public function credit(
        User $user,
        float $amount,
        ?string $reference = null,
        ?string $description = null
    ): Wallet {
        if ($amount <= 0) {
            throw new RuntimeException(
                'Credit amount must be greater than zero.'
            );
        }

        return DB::transaction(function () use (
            $user,
            $amount,
            $reference,
            $description
        ) {
            $wallet = $user->wallet()
                ->lockForUpdate()
                ->firstOrCreate([
                    'user_id' => $user->id,
                ]);

            $wallet->balance += $amount;
            $wallet->save();

            $wallet->transactions()->create([
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $wallet->balance,
                'reference' => $reference,
                'description' => $description,
            ]);

            return $wallet->fresh();
        });
    }

    public function debit(
        User $user,
        float $amount,
        ?string $reference = null,
        ?string $description = null
    ): Wallet {
        if ($amount <= 0) {
            throw new RuntimeException(
                'Debit amount must be greater than zero.'
            );
        }

        return DB::transaction(function () use (
            $user,
            $amount,
            $reference,
            $description
        ) {
            $wallet = $user->wallet()
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                throw new RuntimeException(
                    'Wallet not found.'
                );
            }

            if ($wallet->balance < $amount) {
                throw new RuntimeException(
                    'Insufficient wallet balance.'
                );
            }

            $wallet->balance -= $amount;
            $wallet->save();

            $wallet->transactions()->create([
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $wallet->balance,
                'reference' => $reference,
                'description' => $description,
            ]);

            return $wallet->fresh();
        });
    }
}
