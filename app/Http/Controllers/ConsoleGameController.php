<?php

namespace App\Http\Controllers;

use App\Models\Console;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConsoleGameController extends Controller
{
    public function store(Request $request, Console $console)
    {
        Gate::authorize('manageGames', $console);

        $validated = $request->validate([
            'game_id' => ['required', 'exists:games,id'],
        ]);

        $console->games()->syncWithoutDetaching([
            $validated['game_id'] => [
                'status' => 'pending',
            ],
        ]);

        return back()->with(
            'success',
            'Game added to console successfully.'
        );
    }

    public function destroy(Console $console, Game $game)
    {
        Gate::authorize('manageGames', $console);

        $console->games()->detach($game->id);

        return back()->with(
            'success',
            'Game removed from console successfully.'
        );
    }
}
