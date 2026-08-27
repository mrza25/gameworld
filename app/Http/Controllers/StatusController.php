<?php

namespace App\Http\Controllers;

use App\Models\Console;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConsoleStatusController extends Controller
{
    public function update(Request $request, Console $console)
    {
        Gate::authorize('updateStatus', $console);

        $validated = $request->validate([
            'status' => [
                'required',
                'in:pending,installing,delivered',
            ],
        ]);

        $console->update([
            'status' => $validated['status'],
        ]);

        return back()->with(
            'success',
            'Console status updated successfully.'
        );
    }
}
