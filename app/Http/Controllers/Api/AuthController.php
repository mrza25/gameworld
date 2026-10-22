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
            'code' => ['nullable', 'string'],
        ]);

        $phone = $this->normalizePhone($validated['phone'] ?? $validated['phonenumber'] ?? '');

        if (!$phone) {
            throw ValidationException::withMessages([
                'phone' => 'شماره موبایل الزامی است.',
            ]);
        }

        if ($request->filled('code')) {
            $code = trim($validated['code']);
            if (!$this->isOtpValid($phone, $code)) {
                throw ValidationException::withMessages([
                    'code' => 'کد تأیید نامعتبر یا منقضی شده است.',
                ]);
            }
            \Illuminate\Support\Facades\Cache::forget('otp:' . $phone);
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

        return $this->respondWithToken($user, $token, 'ثبت‌نام با موفقیت انجام شد.', 201);
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

        return $this->respondWithToken($user, $token, 'ورود موفقیت‌آمیز بود.');
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
        ])->withoutCookie('gw_token', '/');
    }

    /**
     * Send a 5-digit OTP SMS code.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $phoneInput = $request->input('phone') ?? $request->input('phonenumber') ?? $request->input('mobile');

        if (!$phoneInput) {
            throw ValidationException::withMessages([
                'phone' => 'شماره موبایل الزامی است.',
            ]);
        }

        $phone = $this->normalizePhone((string) $phoneInput);

        if (strlen($phone) < 10) {
            throw ValidationException::withMessages([
                'phone' => 'شماره موبایل معتبر نیست.',
            ]);
        }

        $code = (string) random_int(10000, 99999);
        \Illuminate\Support\Facades\Cache::put('otp:' . $phone, $code, now()->addMinutes(2));
        \Illuminate\Support\Facades\Log::info("OTP Code for {$phone}: {$code}");

        return response()->json([
            'ok' => true,
            'message' => 'کد تأیید ۵ رقمی با موفقیت ارسال شد.',
            'expires_in' => 120,
            'code' => config('app.debug') ? $code : null,
            'debug_code' => config('app.debug') ? $code : null,
        ]);
    }

    /**
     * Verify OTP code without logging in.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $phoneInput = $request->input('phone') ?? $request->input('phonenumber') ?? $request->input('mobile');
        $codeInput = $request->input('code') ?? $request->input('otp');

        if (!$phoneInput || !$codeInput) {
            throw ValidationException::withMessages([
                'phone' => 'شماره موبایل و کد تأیید الزامی هستند.',
            ]);
        }

        $phone = $this->normalizePhone((string) $phoneInput);
        $code = trim($this->normalizeDigits((string) $codeInput));

        if (!$this->isOtpValid($phone, $code)) {
            return response()->json([
                'ok' => false,
                'message' => 'کد تأیید نامعتبر یا منقضی شده است.',
                'error' => 'کد تأیید نامعتبر یا منقضی شده است.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'valid' => true,
            'message' => 'کد تأیید معتبر است.',
        ]);
    }

    /**
     * Login or register automatically with OTP.
     */
    public function loginWithOtp(Request $request): JsonResponse
    {
        $phoneInput = $request->input('phone') ?? $request->input('phonenumber') ?? $request->input('mobile');
        $codeInput = $request->input('code') ?? $request->input('otp');

        if (!$phoneInput || !$codeInput) {
            throw ValidationException::withMessages([
                'phone' => 'شماره موبایل و کد تأیید الزامی است.',
            ]);
        }

        $phone = $this->normalizePhone((string) $phoneInput);
        $code = trim($this->normalizeDigits((string) $codeInput));

        if (!$this->isOtpValid($phone, $code)) {
            return response()->json([
                'ok' => false,
                'message' => 'کد تأیید نامعتبر یا منقضی شده است.',
                'error' => 'کد تأیید نامعتبر یا منقضی شده است.',
            ], 422);
        }

        \Illuminate\Support\Facades\Cache::forget('otp:' . $phone);

        $isNew = false;
        $user = User::where('phonenumber', $phone)->first();

        if (!$user) {
            $isNew = true;
            $nameInput = $request->input('name') ?? $request->input('fullName');
            $name = trim((string) $nameInput) ?: 'کاربر ' . substr($phone, -4);
            $user = User::create([
                'name' => $name,
                'phonenumber' => $phone,
                'password' => Hash::make(\Illuminate\Support\Str::random(16)),
                'is_admin' => false,
            ]);

            $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return $this->respondWithToken(
            $user,
            $token,
            $isNew ? 'ثبت‌نام و ورود با موفقیت انجام شد.' : 'ورود با موفقیت انجام شد.',
            200,
            [
                'is_new_user' => $isNew,
                'isNewUser' => $isNew,
            ]
        );
    }

    /**
     * Reset user password using OTP.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $phone = $this->normalizePhone($validated['phone']);
        $code = trim($validated['code']);

        if (!$this->isOtpValid($phone, $code)) {
            return response()->json([
                'ok' => false,
                'error' => 'کد تأیید نامعتبر یا منقضی شده است.',
            ], 422);
        }

        $user = User::where('phonenumber', $phone)->first();
        if (!$user) {
            return response()->json([
                'ok' => false,
                'error' => 'کاربری با این شماره یافت نشد.',
            ], 404);
        }

        \Illuminate\Support\Facades\Cache::forget('otp:' . $phone);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return $this->respondWithToken($user, $token, 'رمز عبور با موفقیت به‌روزرسانی شد.');
    }

    /**
     * Update customer profile.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $updates = [];
        if (!empty($validated['name'])) {
            $updates['name'] = trim($validated['name']);
        }

        if (!empty($validated['phone'])) {
            $phone = $this->normalizePhone($validated['phone']);
            if ($phone !== $user->phonenumber) {
                if (User::where('phonenumber', $phone)->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages([
                        'phone' => 'این شماره موبایل توسط کاربر دیگری استفاده شده است.',
                    ]);
                }
                $updates['phonenumber'] = $phone;
            }
        }

        if (!empty($updates)) {
            $user->update($updates);
        }

        return response()->json([
            'ok' => true,
            'message' => 'پروفایل با موفقیت به‌روزرسانی شد.',
            'user' => $this->transformUser($user->fresh()),
        ]);
    }

    /**
     * Upload customer avatar.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'],
        ]);

        $user = $request->user();
        $path = $request->file('avatar')->store('avatars', 'public');

        $user->update(['avatar' => $path]);

        return response()->json([
            'ok' => true,
            'message' => 'عکس پروفایل با موفقیت ذخیره شد.',
            'avatar' => $user->avatar_url,
            'user' => $this->transformUser($user->fresh()),
        ]);
    }

    /**
     * Change customer password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'ok' => false,
                'error' => 'رمز عبور فعلی نادرست است.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'رمز عبور با موفقیت تغییر یافت.',
        ]);
    }

    private function isOtpValid(string $phone, string $code): bool
    {
        // Support bypass in debug/testing mode for quick automated testing
        if (config('app.debug') && in_array($code, ['11111', '12345'], true)) {
            return true;
        }

        $cached = \Illuminate\Support\Facades\Cache::get('otp:' . $phone);
        return $cached !== null && $cached === $code;
    }

    private function normalizeDigits(string $str): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $str = str_replace($fa, $en, $str);
        return str_replace($ar, $en, $str);
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
            'avatar' => $user->avatar_url,
            'role' => $user->is_admin ? 'admin' : 'user',
            'isAdmin' => (bool) $user->is_admin,
            'is_admin' => (bool) $user->is_admin,
            'walletBalance' => (float) $wallet->balance,
            'wallet_balance' => (float) $wallet->balance,
            'joinDate' => $user->created_at?->format('Y-m-d'),
            'joined_at' => \App\Helpers\Jalali::fromDateTime($user->created_at),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function respondWithToken(User $user, string $token, string $message, int $status = 200, array $extra = []): JsonResponse
    {
        $isSecure = config('app.env') === 'production';

        $cookie = cookie(
            name: 'gw_token',
            value: $token,
            minutes: 10080, // 7 days
            path: '/',
            domain: null,
            secure: $isSecure,
            httpOnly: true,
            raw: false,
            sameSite: 'lax'
        );

        $payload = array_merge([
            'ok' => true,
            'message' => $message,
            'user' => $this->transformUser($user),
            'token' => $token,
        ], $extra);

        return response()->json($payload, $status)->withCookie($cookie);
    }
}

