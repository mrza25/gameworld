<?php

namespace App\Http\Controllers;

use App\Models\Console;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;

class AdminConsoleController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Console::class);

        $consoles = Console::with('user')
            ->latest()
            ->get();

        return view('admin.consoles.index', compact('consoles'));
    }

    public function show(Console $console)
    {
        Gate::authorize('view', $console);

        $console->load([
            'user',
            'games',
        ]);

        return view('admin.consoles.show', compact('console'));
    }
}
