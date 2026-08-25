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
        $request->validate([
            'name' => 'required|string|max:255',
            'phonenumber' => 'required|numeric',
            'password' => 'required|string|min:8|max:255',
        ]);

        $user =User::create([
            'name' => $request->name,
            'phonenumber' => $request->phonenumber,
            'password' => bcrypt($request->password),
        ]);
        Auth::login($user);

        return redirect()->intended('/');
    }
}
