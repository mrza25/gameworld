<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }
    public function store(Request $request)
{
    $validated = $request->validate([
        'phonenumber' => ['required', 'numeric'],
        'password' => ['required', 'string', 'min:8', 'max:255'],
    ]);

    if (Auth::attempt($validated)) {
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
    else {
        return back()->withErrors([
            'phonenumber' => 'The provided phone number or password is incorrect.',
        ])->onlyInput('phonenumber');
    }
}
    public function destroy()
    {
        Auth::logout();
        return redirect('/login');
    }
}
