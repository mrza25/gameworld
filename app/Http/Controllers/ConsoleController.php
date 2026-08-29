<?php

namespace App\Http\Controllers;

use App\Models\Console;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConsoleController extends Controller
{
    public function index()
    {
        $consoles = Auth::user()
            ->consoles()
            ->with('games')
            ->latest()
            ->get();

        return view('consoles.index', compact('consoles'));
    }

    public function create()
    {
        $games = Game::orderBy('title')->get();

        return view('consoles.create', compact('games'));
    }

    public function store(Request $request)
{
    $validated = $request->validate([
        'type' => 'required|string|max:255',

        'games' => 'nullable|array',

        'games.*' => 'integer|exists:games,id',
    ]);

    DB::transaction(function () use ($validated) {

        $console = Console::create([
            'user_id' => Auth::id(),
            'type' => $validated['type'],
            'status' => 'pending',
        ]);

        if (!empty($validated['games'])) {

            $pivotData = [];

            foreach ($validated['games'] as $gameId) {

                $pivotData[$gameId] = [
                    'status' => 'pending',
                ];
            }

            $console->games()->attach($pivotData);
        }
    });

    return redirect()
        ->route('consoles.index')
        ->with('success', 'Console created successfully.');
}
}
