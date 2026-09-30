<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Auth::user()
            ->orders()
            ->with('items.game')
            ->latest()
            ->get();

        return view('orders.index', compact('orders'));
    }

    public function create()
    {
        $games = Game::orderBy('title')->get();

        return view('orders.create', compact('games'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'console_type' => [
                'required',
                'string',
                'max:255',
            ],

            'games' => [
                'required',
                'array',
                'min:1',
            ],

            'games.*' => [
                'integer',
                'exists:games,id',
                'distinct',
            ],
        ]);

        /*
         * فعلاً هیچ Order ساخته نمی‌شود.
         *
         * اطلاعات انتخاب‌شده را به Checkout منتقل می‌کنیم.
         */

        $request->session()->put(
            'checkout',
            [
                'console_type' => $validated['console_type'],
                'games' => $validated['games'],
            ]
        );

        return redirect()
            ->route('checkout.index');
    }

    public function show(Order $order)
    {
        abort_unless(
            $order->user_id === Auth::id(),
            403
        );

        $order->load('items.game');

        return view('orders.show', compact('order'));
    }
}
