<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\OrderStatus;
use App\PaymentStatus;

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
            'console_type' => ['required', 'string', 'max:255'],

            'games' => ['required', 'array', 'min:1'],

            'games.*' => [
                'integer',
                'exists:games,id',
                'distinct',
            ],
        ]);

        $games = Game::whereIn(
            'id',
            $validated['games']
        )->get();

        $order = DB::transaction(function () use ($validated, $games) {

            $total = $games->sum('price');

            $order = Order::create([
                'user_id' => Auth::id(),
                'console_type' => $validated['console_type'],
                'total' => $total,
                'status' => 'pending',
                'payment_status' => 'unpaid',
            ]);

            foreach ($games as $game) {
                $order->items()->create([
    'game_id' => $game->id,
    'quantity' => 1,
    'price' => $game->price,
    'installation_status' => 'pending',
]);
            }

            return $order;
        });

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Order created successfully.');
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
