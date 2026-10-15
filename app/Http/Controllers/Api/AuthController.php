<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Check if a phone number is already registered.
     */
    public function checkPhone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $phone = $this->normalizePhone($validated['phone']);

        $exists = User::where('phonenumber', $phone)->exists();

        return response()->json([
            'ok' => true,
            'exists' => $exists,
        ]);
    }

    /**
     * Register a new user and generate access token.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'firstName' => ['nullable', 'string', 'max:255'],
            'lastName' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'phonenumber' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $phone = $this->normalizePhone($validated['phone'] ?? $validated['phonenumber'] ?? '');

        if (!$phone) {
            throw ValidationException::withMessages([
                'phone' => 'شماره موبایل الزامی است.',
            ]);
        }

        if (User::where('phonenumber', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'این شماره قبلاً ثبت‌نام کرده است.',
            ]);
        }

        $fullName = trim($validated['name'] ?? ($validated['firstName'] . ' ' . ($validated['lastName'] ?? '')));
        if (!$fullName) {
            $fullName = 'کاربر ' . substr($phone, -4);
        }

        $user = User::create([
            'name' => $fullName,
            'phonenumber' => $phone,
            'password' => Hash::make($validated['password']),
            'is_admin' => false,
        ]);

        $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'ok' => true,
            'message' => 'ثبت‌نام با موفقیت انجام شد.',
            'user' => $this->transformUser($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Login with phone and password.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string'],
            'phonenumber' => ['nullable', 'string'],
            'password' => ['required', 'string'],
        ]);

        $phone = $this->normalizePhone($validated['phone'] ?? $validated['phonenumber'] ?? '');

        $user = User::where('phonenumber', $phone)->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'ok' => false,
                'error' => 'شماره موبایل یا رمز عبور اشتباه است.',
            ], 401);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'ok' => true,
            'message' => 'ورود موفقیت‌آمیز بود.',
            'user' => $this->transformUser($user),
            'token' => $token,
        ]);
    }

    /**
     * Get the authenticated user's profile and wallet balance.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'ok' => true,
            'user' => $this->transformUser($user),
        ]);
    }

    /**
     * Logout and revoke current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'ok' => true,
            'message' => 'با موفقیت خارج شدید.',
        ]);
    }

    private function normalizePhone(string $phone): string
    {
        // Replace Persian/Arabic digits
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $phone = str_replace($fa, $en, $phone);
        $phone = str_replace($ar, $en, $phone);
        $digits = preg_replace('/\D/', '', $phone);

        // Normalize Iranian numbers (+989... -> 09...)
        if (str_starts_with($digits, '989')) {
            $digits = '0' . substr($digits, 2);
        }

        return $digits;
    }

    private function transformUser(User $user): array
    {
        $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phonenumber,
            'role' => $user->is_admin ? 'admin' : 'user',
            'isAdmin' => (bool) $user->is_admin,
            'walletBalance' => (float) $wallet->balance,
            'joinDate' => $user->created_at?->format('Y-m-d'),
        ];
    }
}

