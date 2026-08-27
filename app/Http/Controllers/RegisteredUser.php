<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RegisteredUser extends Controller
{
    public function create()
    {
        return view('auth.register');
    }

    public function store(Request $request)
{
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'phonenumber' => ['required', 'numeric', 'unique:users,phonenumber'],
        'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
    ]);

    $user = User::create([
        'name' => $validated['name'],
        'phonenumber' => $validated['phonenumber'],
        'password' => bcrypt($validated['password']),
    ]);

    Auth::login($user);

    $request->session()->regenerate();

    return redirect()->intended('/');
}
}
