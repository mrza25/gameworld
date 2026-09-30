<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(
        Request $request,
        WalletService $walletService
    ) {
        $wallet = $walletService->getOrCreateWallet(
            $request->user()
        );

        $transactions = $wallet->transactions()
            ->latest()
            ->get();

        return view(
            'wallet.index',
            compact('wallet', 'transactions')
        );
    }
}
