<?php

namespace App\Http\Controllers;

use App\Models\Console;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Auth::user()
            ->orders()
            ->with([
                'console',
                'items.game',
            ])
            ->latest()
            ->get();

        return view('orders.index', compact('orders'));
    }

    public function create()
    {
        $consoles = Auth::user()
            ->consoles()
            ->with('games')
            ->get();

        return view('orders.create', compact('consoles'));
    }

   public function store(Request $request)
{
    $validated = $request->validate([
        'console_id' => 'required|exists:consoles,id',
        'games' => 'required|array|min:1',
        'games.*' => 'integer|exists:games,id',
    ]);

    $console = Auth::user()
        ->consoles()
        ->with('games')
        ->findOrFail($validated['console_id']);

    $games = $console->games()
        ->whereIn('games.id', $validated['games'])
        ->get();

    if ($games->count() !== count($validated['games'])) {
        return back()
            ->withErrors([
                'games' => 'One or more selected games are not available for this console.',
            ])
            ->withInput();
    }

    $order = DB::transaction(function () use ($console, $games) {

        $total = $games->sum('price');

        $order = Order::create([
            'user_id' => Auth::id(),
            'console_id' => $console->id,
            'total' => $total,
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        foreach ($games as $game) {

            $order->items()->create([
                'game_id' => $game->id,
                'quantity' => 1,
                'price' => $game->price,
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
        /*
         * کاربر فقط می‌تواند سفارش خودش را ببیند.
         */
        abort_unless($order->user_id === Auth::id(), 403);

        $order->load([
            'console',
            'items.game',
        ]);

        return view('orders.show', compact('order'));
    }
}
