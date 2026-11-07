<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\WalletBalanceAdjusted;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Managing customer and admin accounts.
 */
class AdminUserController extends Controller
{
    public function __construct(
        private WalletService $walletService
    ) {}

    /**
     * List users with search and pagination for admin.
     */
    public function users(Request $request): JsonResponse
    {
        $query = User::with('wallet')->withCount('orders')->latest();

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($sq) use ($q) {
                $sq->where('name', 'like', "%{$q}%")
                    ->orWhere('phonenumber', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        if (in_array($request->input('role'), ['admin', 'user'], true)) {
            $query->where('is_admin', $request->input('role') === 'admin');
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $users = $query->paginate($this->perPage($request));
        $users->getCollection()->transform(fn (User $user) => $this->adminUserArray($user));
        $paginatorArray = $users->toArray();
        $pagination = $this->paginationDetails($users);
        $paginatorArray['meta'] = $pagination['meta'];

        return response()->json([
            'ok' => true,
            'data' => $paginatorArray,
            'users' => $users->items(),
        ] + $pagination);
    }

    /**
     * Get a single user for the admin user editor.
     */
    public function userDetail(int $id): JsonResponse
    {
        $user = User::with('wallet')->withCount('orders')->findOrFail($id);
        $userData = $this->adminUserArray($user);

        return response()->json([
            'ok' => true,
            'data' => $userData,
            'user' => $userData,
        ]);
    }

    /**
     * Create a user by admin.
     */
    public function storeUser(Request $request): JsonResponse
    {
        $this->mergeRoleAlias($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phonenumber'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'is_admin' => ['nullable', 'boolean'],
            'wallet_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'phonenumber' => $validated['phone'],
            'email' => isset($validated['email']) ? Str::lower($validated['email']) : null,
            'password' => Hash::make($validated['password']),
            'is_admin' => $validated['is_admin'] ?? false,
        ]);

        $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        if (! empty($validated['wallet_balance'])) {
            $this->adjustWalletBalance($user, (float) $validated['wallet_balance']);
        }

        return response()->json([
            'ok' => true,
            'message' => 'کاربر با موفقیت ایجاد شد.',
            'data' => $user->load('wallet'),
        ], 201);
    }

    /**
     * Update user by admin.
     */
    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $this->mergeRoleAlias($request);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phonenumber,'.$id],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'is_admin' => ['nullable', 'boolean'],
            'wallet_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($user->is($request->user()) && isset($validated['is_admin']) && ! $validated['is_admin']) {
            throw ValidationException::withMessages([
                'role' => 'نمی‌توانید دسترسی مدیریت خودتان را بردارید.',
            ]);
        }

        if ($request->has('email')) {
            $user->email = isset($validated['email']) ? Str::lower($validated['email']) : null;
        }

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (isset($validated['phone'])) {
            $user->phonenumber = $validated['phone'];
        }
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        if (isset($validated['is_admin'])) {
            $user->is_admin = $validated['is_admin'];
        }
        $user->save();

        // Whoever held the old password must not stay signed in with it.
        if (! empty($validated['password'])) {
            $user->revokeTokensExcept($request->user()->currentAccessToken());
        }

        if (isset($validated['wallet_balance'])) {
            $this->adjustWalletBalance($user, (float) $validated['wallet_balance']);
        }

        return response()->json([
            'ok' => true,
            'message' => 'کاربر با موفقیت ویرایش شد.',
            'data' => $user->fresh(['wallet']),
        ]);
    }

    /**
     * Delete user by admin.
     */
    public function deleteUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->is($request->user())) {
            return response()->json([
                'ok' => false,
                'error' => 'نمی‌توانید حساب خودتان را حذف کنید.',
            ], 422);
        }

        if ($user->orders()->exists()) {
            return response()->json([
                'ok' => false,
                'error' => 'به دلیل وجود سفارشات فعال، امکان حذف این کاربر وجود ندارد.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'ok' => true,
            'message' => 'کاربر با موفقیت حذف شد.',
        ]);
    }

    /**
     * Set a wallet to the given balance through the ledger so every admin
     * adjustment leaves an auditable wallet transaction.
     */
    private function adjustWalletBalance(User $user, float $targetBalance): void
    {
        $wallet = $this->walletService->getOrCreateWallet($user);
        $previousBalance = (float) $wallet->balance;
        $difference = round($targetBalance - $previousBalance, 2);

        if ($difference === 0.0) {
            return;
        }

        $wallet = $difference > 0
            ? $this->walletService->credit($user, $difference, 'admin-adjustment', 'Balance adjusted by admin')
            : $this->walletService->debit($user, abs($difference), 'admin-adjustment', 'Balance adjusted by admin');

        $user->notify(new WalletBalanceAdjusted($previousBalance, (float) $wallet->balance));
    }

    /**
     * A user row for the admin panel: the model's columns plus the names the
     * frontend uses.
     *
     * @return array<string, mixed>
     */
    private function adminUserArray(User $user): array
    {
        return $user->toArray() + [
            'phone' => $user->phonenumber,
            'role' => $user->is_admin ? 'admin' : 'user',
            'wallet_balance' => (float) ($user->wallet?->balance ?? 0),
            'avatar_url' => $user->avatar_url,
        ];
    }

    /**
     * Accept role ("admin" | "user") as an alias for is_admin.
     */
    private function mergeRoleAlias(Request $request): void
    {
        if (! $request->has('is_admin') && in_array($request->input('role'), ['admin', 'user'], true)) {
            $request->merge(['is_admin' => $request->input('role') === 'admin']);
        }
    }
}
