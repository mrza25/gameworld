<?php

namespace App\Http\Controllers;

use App\Models\Console;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use App\Models\Game;
class ConsoleController extends Controller
{
    public function index()
    {
        $consoles = Auth::user()
            ->consoles()
            ->latest()
            ->get();

        return view('consoles.index', compact('consoles'));
    }

    public function create()
    {
        return view('consoles.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:255'],
        ]);

        Auth::user()->consoles()->create($validated);

        return redirect()
            ->route('consoles.index')
            ->with('success', 'Console added successfully.');
    }

    public function show(Console $console)
{
    Gate::authorize('view', $console);

    $console->load('games');

    $games = \App\Models\Game::orderBy('title')->get();

    return view('consoles.show', compact('console', 'games'));
}
    public function edit(Console $console)
    {
        Gate::authorize('update', $console);

        return view('consoles.edit', compact('console'));
    }

    public function update(Request $request, Console $console)
    {
        Gate::authorize('update', $console);

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:255'],
        ]);

        $console->update($validated);

        return redirect()
            ->route('consoles.index')
            ->with('success', 'Console updated successfully.');
    }

    public function destroy(Console $console)
    {
        Gate::authorize('delete', $console);

        $console->delete();

        return redirect()
            ->route('consoles.index')
            ->with('success', 'Console deleted successfully.');
    }
}
