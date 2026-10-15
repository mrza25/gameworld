<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        if ($request->filled('phonenumber')) {
            $request->merge([
                'phonenumber' => $this->normalizePhone((string) $request->input('phonenumber')),
            ]);
        }

        $credentials = $request->validate([
            'phonenumber' => 'required|string',
            'password' => 'required|string',
        ]);

        if (Auth::attempt($credentials)) {

            $request->session()->regenerate();

            return redirect()
                ->intended(route('home'))
                ->with('success', 'Welcome back!');
        }

        return back()
            ->withErrors([
                'phonenumber' => 'The phone number or password is incorrect.',
            ])
            ->onlyInput('phonenumber');
    }


    public function showRegister()
    {
        return view('auth.register');
    }


    public function register(Request $request)
    {
        if ($request->filled('phonenumber')) {
            $request->merge([
                'phonenumber' => $this->normalizePhone((string) $request->input('phonenumber')),
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',

            'phonenumber' => [
                'required',
                'string',
                'max:20',
                'unique:users,phonenumber',
            ],

            'password' => 'required|string|min:8|confirmed',
        ]);


        $user = User::create([
            'name' => $validated['name'],
            'phonenumber' => $validated['phonenumber'],
            'password' => Hash::make($validated['password']),
        ]);
        $user->wallet()->create([
    'balance' => 0,
]);


        Auth::login($user);

        $request->session()->regenerate();

        return redirect()
            ->route('home')
            ->with('success', 'Your account has been created.');
    }


    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()
            ->route('home')
            ->with('success', 'You have been logged out.');
    }

    private function normalizePhone(string $phone): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $phone = str_replace($fa, $en, $phone);
        $phone = str_replace($ar, $en, $phone);
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '989')) {
            $digits = '0' . substr($digits, 2);
        }

        return $digits;
    }
}
