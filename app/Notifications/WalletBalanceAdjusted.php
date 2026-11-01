<?php

namespace App\Notifications;

class WalletBalanceAdjusted extends UserNotification
{
    public function __construct(float $previousBalance, float $balance)
    {
        $this->compose(
            'wallet_adjusted',
            'تغییر موجودی کیف پول',
            'موجودی کیف پول شما توسط پشتیبانی از '.self::toman($previousBalance).' به '.self::toman($balance).' تغییر کرد.',
            '/my/wallet',
            [
                'previous_balance' => $previousBalance,
                'balance' => $balance,
                'amount' => round($balance - $previousBalance, 2),
            ]
        );
    }
}
