<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function index()
    {
        $games = Game::latest()->get();

        return view('games.index', compact('games'));
    }

    public function create()
    {
        return view('games.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'genre' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:255'],
            'size' => ['required', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        Game::create($validated);

        return redirect()
            ->route('games.index')
            ->with('success', 'Game created successfully.');
    }

    public function show(Game $game)
    {
        return view('games.show', compact('game'));
    }

    public function edit(Game $game)
    {
        return view('games.edit', compact('game'));
    }

    public function update(Request $request, Game $game)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'genre' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:255'],
            'size' => ['required', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        $game->update($validated);

        return redirect()
            ->route('games.show', $game)
            ->with('success', 'Game updated successfully.');
    }

    public function destroy(Game $game)
    {
        $game->delete();

        return redirect()
            ->route('games.index')
            ->with('success', 'Game deleted successfully.');
    }
}
