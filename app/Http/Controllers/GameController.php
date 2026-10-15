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

    public function adminIndex()
{
    $games = Game::latest()->get();

    return view('admin.games.index', compact('games'));
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
            'platform' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'developer' => ['nullable', 'string', 'max:255'],
            'release_date' => ['nullable', 'date'],
            'age_rating' => ['nullable', 'integer', 'min:0', 'max:18'],
            'image' => ['nullable', 'string', 'max:500'],
        ]);

        $baseSlug = \Illuminate\Support\Str::slug($validated['title']);
        $slug = $baseSlug ?: 'game-' . time();
        $counter = 1;
        while (Game::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        Game::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'genre' => $validated['genre'],
            'platform' => $validated['platform'] ?? 'Multi',
            'size' => $validated['size'] ?? 0,
            'price' => $validated['price'] ?? 0,
            'description' => $validated['description'] ?? null,
            'developer' => $validated['developer'] ?? null,
            'release_date' => $validated['release_date'] ?? null,
            'age_rating' => $validated['age_rating'] ?? null,
            'image' => $validated['image'] ?? null,
            'is_available' => true,
        ]);

        return redirect()
            ->route('admin.games.index')
            ->with('success', 'Game created successfully.');
    }

    public function show(Game $game)
    {
        $game->load('variants.platform');

        return view('games.show', compact('game'));
    }

    public function edit(Game $game)
    {
        $game->load('variants.platform');

        return view('games.edit', compact('game'));
    }

    public function update(Request $request, Game $game)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'genre' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'developer' => ['nullable', 'string', 'max:255'],
            'release_date' => ['nullable', 'date'],
            'age_rating' => ['nullable', 'integer', 'min:0', 'max:18'],
            'image' => ['nullable', 'string', 'max:500'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $game->update($validated);

        return redirect()
            ->route('admin.games.index')
            ->with('success', 'Game updated successfully.');
    }

    public function destroy(Game $game)
    {
        if ($game->orderItems()->exists()) {
            return redirect()
                ->route('admin.games.index')
                ->with('error', 'Cannot delete a game that has associated customer orders.');
        }

        $game->delete();

        return redirect()
            ->route('admin.games.index')
            ->with('success', 'Game deleted successfully.');
    }
}
