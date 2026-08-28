<?php

namespace App\Http\Controllers;

use App\Models\Console;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use App\Models\Game;
class ConsoleController extends Controller
{
    /**
     * Display a listing of consoles.
     */
    public function index()
    {
        $consoles = Auth::user()
            ->consoles()
            ->with('games')
            ->latest()
            ->get();

        return view('consoles.index', compact('consoles'));
    }

    /**
     * Show the form for creating a new console.
     */
   public function create()
{
    $games = Game::orderBy('title')->get();

    return view('consoles.create', compact('games'));
}

    /**
     * Store a newly created console.
     */
    public function store(Request $request)
{
    $validated = $request->validate([
        'type' => [
            'required',
            'string',
            'max:255',
        ],

        'games' => [
            'nullable',
            'array',
        ],

        'games.*' => [
            'integer',
            'exists:games,id',
        ],
    ]);

    $console = Auth::user()->consoles()->create([
        'type' => $validated['type'],
    ]);

    if (!empty($validated['games'])) {
        $console->games()->attach($validated['games']);
    }

    return redirect()
        ->route('consoles.show', $console)
        ->with('success', 'Console registered successfully.');
}

    /**
     * Display the specified console.
     */
    public function show(Console $console)
{
    Gate::authorize('view', $console);

    $console->load('games');

    $games = Game::orderBy('title')->get();

    return view('consoles.show', compact('console', 'games'));
}

    /**
     * Show the form for editing the specified console.
     */
    public function edit(Console $console)
    {
        Gate::authorize('update', $console);

        return view('consoles.edit', compact('console'));
    }

    /**
     * Update the specified console.
     */
    public function update(Request $request, Console $console)
    {
        Gate::authorize('update', $console);

        $validated = $request->validate([
            'type' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $console->update($validated);

        return redirect()
            ->route('consoles.show', $console)
            ->with('success', 'Console updated successfully.');
    }

    /**
     * Remove the specified console.
     */
    public function destroy(Console $console)
    {
        Gate::authorize('delete', $console);

        $console->delete();

        return redirect()
            ->route('consoles.index')
            ->with('success', 'Console deleted successfully.');
    }
}
