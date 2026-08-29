<?php

namespace App\Http\Controllers;

use App\Enums\ConsoleGameStatus;
use App\Enums\ConsoleStatus;
use App\Models\Console;
use App\Models\Game;
use Illuminate\Http\Request;

class AdminConsoleController extends Controller
{
    public function index()
    {
        $consoles = Console::with([
            'user',
            'games',
        ])
            ->latest()
            ->get();

        return view(
            'admin.consoles.index',
            compact('consoles')
        );
    }

    public function show(Console $console)
    {
        $console->load([
            'user',
            'games',
        ]);

        return view(
            'admin.consoles.show',
            compact('console')
        );
    }

    public function updateStatus(
        Request $request,
        Console $console
    ) {
        $validated = $request->validate([
            'status' => 'required|in:pending,installing,delivered',
        ]);

        $console->update([
            'status' => $validated['status'],
        ]);

        return back()->with(
            'success',
            'Console status updated successfully.'
        );
    }
public function updateGameStatus(
    Request $request,
    Console $console,
    Game $game
) {
    $validated = $request->validate([
        'status' => 'required|in:pending,installing,installed',
    ]);

    if (! $console->games()->whereKey($game->id)->exists()) {
        abort(404);
    }

    $data = [
        'status' => $validated['status'],
    ];

    if ($validated['status'] === 'installed') {
        $data['installed_at'] = now();
    } else {
        $data['installed_at'] = null;
    }

    $console->games()->updateExistingPivot(
        $game->id,
        $data
    );

    $console->load('games');

    $allGamesInstalled =
        $console->games->isNotEmpty()
        &&
        $console->games->every(
            fn ($game) =>
                $game->pivot->status === 'installed'
        );

    if ($allGamesInstalled) {

        $console->update([
            'status' => 'delivered',
        ]);

    } else {

        $console->update([
            'status' => 'installing',
        ]);

    }

    return back()->with(
        'success',
        'Game status updated successfully.'
    );
}
}
