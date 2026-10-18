<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameVariant;
use App\Models\Order;
use App\Models\Platform;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    /**
     * Admin Dashboard statistics and recent orders.
     */
    public function dashboard(): JsonResponse
    {
        $stats = [
            'games' => Game::count(),
            'orders' => Order::count(),
            'users' => User::count(),
            'pending_orders' => Order::where('status', OrderStatus::Pending)->count(),
            'processing_orders' => Order::where('status', OrderStatus::Processing)->count(),
            'completed_orders' => Order::where('status', OrderStatus::Completed)->count(),
        ];

        $recentOrders = Order::with(['user', 'items.game'])
            ->latest()
            ->take(6)
            ->get();

        return response()->json([
            'ok' => true,
            'stats' => $stats,
            'recentOrders' => $recentOrders,
        ]);
    }

    /**
     * List all orders for administrative panel.
     */
    public function orders(Request $request): JsonResponse
    {
        $query = Order::with(['user', 'items.game', 'items.gameVariant.platform', 'payments'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phonenumber', 'like', "%{$search}%");
            });
        }

        $orders = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'ok' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Get single order details for admin.
     */
    public function orderDetail(int $id): JsonResponse
    {
        $order = Order::with(['user', 'items.game', 'items.gameVariant.platform', 'payments'])
            ->findOrFail($id);

        return response()->json([
            'ok' => true,
            'data' => $order,
        ]);
    }

    /**
     * Update order operational status.
     */
    public function updateOrderStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string'],
        ]);

        $order = Order::findOrFail($id);

        // Map Persian status strings if sent from frontend
        $statusKey = $this->resolveStatusKey($validated['status']);

        $oldStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;
        $order->update(['status' => $statusKey]);
        $order->refresh();

        $newStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;
        if ($oldStatus !== $newStatus) {
            $order->user?->notify(new OrderStatusUpdated($order));
        }

        return response()->json([
            'ok' => true,
            'message' => 'وضعیت سفارش با موفقیت به‌روزرسانی شد.',
            'status' => $newStatus,
        ]);
    }

    /**
     * Update order payment status.
     */
    public function updatePaymentStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'payment_status' => ['required', 'string'],
        ]);

        $order = Order::findOrFail($id);
        $paymentKey = $this->resolvePaymentStatusKey($validated['payment_status']);

        $order->update(['payment_status' => $paymentKey]);

        return response()->json([
            'ok' => true,
            'message' => 'وضعیت پرداخت با موفقیت به‌روزرسانی شد.',
            'payment_status' => $paymentKey,
        ]);
    }

    /**
     * Update installation status of a single item in an order.
     */
    public function updateItemInstallationStatus(Request $request, int $orderId, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'installation_status' => ['required', 'string'],
        ]);

        $order = Order::findOrFail($orderId);
        $item = $order->items()->findOrFail($itemId);

        $installKey = $this->resolveInstallStatusKey($validated['installation_status']);

        $oldOrderStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;

        $item->update([
            'installation_status' => $installKey,
            'installed_at' => $installKey === 'installed' ? now() : null,
        ]);

        $order->syncStatusFromItems();
        $order->refresh();

        $newOrderStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;
        if ($oldOrderStatus !== $newOrderStatus) {
            $order->user?->notify(new OrderStatusUpdated($order));
        }

        return response()->json([
            'ok' => true,
            'message' => 'وضعیت نصب بازی به‌روزرسانی شد.',
            'item' => $item->fresh(),
            'order_status' => $newOrderStatus,
        ]);
    }

    /**
     * List users with search and pagination for admin.
     */
    public function users(Request $request): JsonResponse
    {
        $query = User::with('wallet')->latest();

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where('name', 'like', "%{$q}%")
                  ->orWhere('phonenumber', 'like', "%{$q}%");
        }

        $users = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'ok' => true,
            'data' => $users,
        ]);
    }

    /**
     * Create a user by admin.
     */
    public function storeUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phonenumber'],
            'password' => ['required', 'string', 'min:6'],
            'is_admin' => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'phonenumber' => $validated['phone'],
            'password' => \Illuminate\Support\Facades\Hash::make($validated['password']),
            'is_admin' => $validated['is_admin'] ?? false,
        ]);

        $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

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

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phonenumber,' . $id],
            'password' => ['nullable', 'string', 'min:6'],
            'is_admin' => ['nullable', 'boolean'],
            'wallet_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (isset($validated['name'])) $user->name = $validated['name'];
        if (isset($validated['phone'])) $user->phonenumber = $validated['phone'];
        if (!empty($validated['password'])) $user->password = \Illuminate\Support\Facades\Hash::make($validated['password']);
        if (isset($validated['is_admin'])) $user->is_admin = $validated['is_admin'];
        $user->save();

        if (isset($validated['wallet_balance'])) {
            $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
            $wallet->update(['balance' => $validated['wallet_balance']]);
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
    public function deleteUser(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
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
     * Add a game with platform variants.
     */
    public function storeGame(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:games,slug'],
            'genre' => ['required', 'string', 'max:100'],
            'developer' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'age_rating' => ['nullable', 'integer', 'min:3', 'max:21'],
            'image' => ['nullable'],
            'is_available' => ['nullable', 'boolean'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.platform_id' => ['required', 'exists:platforms,id'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.size' => ['required', 'numeric', 'min:0'],
            'variants.*.is_available' => ['nullable', 'boolean'],
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('games', 'public');
        } elseif (is_string($request->input('image'))) {
            $imagePath = $request->input('image');
        }

        $slug = $validated['slug'] ?? Str::slug($validated['title']);
        if (Game::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $game = Game::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'genre' => $validated['genre'],
            'developer' => $validated['developer'] ?? 'PlayStation',
            'description' => $validated['description'] ?? null,
            'age_rating' => $validated['age_rating'] ?? 16,
            'image' => $imagePath,
            'is_available' => $validated['is_available'] ?? true,
        ]);

        foreach ($validated['variants'] as $v) {
            $game->variants()->create([
                'platform_id' => $v['platform_id'],
                'price' => $v['price'],
                'size' => $v['size'],
                'is_available' => $v['is_available'] ?? true,
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'بازی با موفقیت ایجاد شد.',
            'data' => $game->load('variants.platform'),
        ], 201);
    }

    /**
     * Update a game and its platform variants.
     */
    public function updateGame(Request $request, int $id): JsonResponse
    {
        $game = Game::findOrFail($id);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'genre' => ['nullable', 'string', 'max:100'],
            'developer' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'age_rating' => ['nullable', 'integer', 'min:3', 'max:21'],
            'image' => ['nullable'],
            'is_available' => ['nullable', 'boolean'],
            'variants' => ['nullable', 'array'],
            'variants.*.platform_id' => ['required_with:variants', 'exists:platforms,id'],
            'variants.*.price' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.size' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.is_available' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            $game->image = $request->file('image')->store('games', 'public');
        } elseif (is_string($request->input('image'))) {
            $game->image = $request->input('image');
        }

        if (isset($validated['title'])) $game->title = $validated['title'];
        if (isset($validated['genre'])) $game->genre = $validated['genre'];
        if (isset($validated['developer'])) $game->developer = $validated['developer'];
        if (isset($validated['description'])) $game->description = $validated['description'];
        if (isset($validated['age_rating'])) $game->age_rating = $validated['age_rating'];
        if (isset($validated['is_available'])) $game->is_available = $validated['is_available'];
        $game->save();

        if (!empty($validated['variants'])) {
            foreach ($validated['variants'] as $v) {
                $game->variants()->updateOrCreate(
                    ['platform_id' => $v['platform_id']],
                    [
                        'price' => $v['price'],
                        'size' => $v['size'],
                        'is_available' => $v['is_available'] ?? true,
                    ]
                );
            }
        }

        return response()->json([
            'ok' => true,
            'message' => 'بازی با موفقیت به‌روزرسانی شد.',
            'data' => $game->fresh(['variants.platform']),
        ]);
    }

    /**
     * Delete a game.
     */
    public function deleteGame(int $id): JsonResponse
    {
        $game = Game::findOrFail($id);

        if ($game->orderItems()->exists()) {
            return response()->json([
                'ok' => false,
                'error' => 'به دلیل وجود در سفارشات قبلی، امکان حذف این بازی وجود ندارد.',
            ], 422);
        }

        $game->variants()->delete();
        $game->delete();

        return response()->json([
            'ok' => true,
            'message' => 'بازی با موفقیت حذف شد.',
        ]);
    }

    /**
     * Get all transactions for admin.
     */
    public function transactions(Request $request): JsonResponse
    {
        $transactions = \App\Models\WalletTransaction::with('wallet.user')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'ok' => true,
            'data' => $transactions,
        ]);
    }

    /**
     * List all banners for admin.
     */
    public function banners(Request $request): JsonResponse
    {
        $banners = \Illuminate\Support\Facades\DB::table('banners')
            ->orderBy('sort_order')
            ->latest()
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $banners,
        ]);
    }

    /**
     * Create a banner.
     */
    public function storeBanner(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'image' => ['required'],
            'link' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('banners', 'public')
            : $validated['image'];

        $id = \Illuminate\Support\Facades\DB::table('banners')->insertGetId([
            'title' => $validated['title'],
            'image' => $imagePath,
            'link' => $validated['link'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'بنر با موفقیت ثبت شد.',
            'id' => $id,
        ], 201);
    }

    /**
     * Delete a banner.
     */
    public function deleteBanner(int $id): JsonResponse
    {
        \Illuminate\Support\Facades\DB::table('banners')->where('id', $id)->delete();

        return response()->json([
            'ok' => true,
            'message' => 'بنر با موفقیت حذف شد.',
        ]);
    }

    private function resolveStatusKey(string $status): string
    {
        return match ($status) {
            'در حال بررسی', 'pending' => 'pending',
            'در حال پردازش', 'تایید شده', 'processing', 'confirmed' => 'processing',
            'تکمیل شده', 'آماده تحویل', 'completed', 'ready' => 'completed',
            default => 'pending',
        };
    }

    private function resolvePaymentStatusKey(string $status): string
    {
        return match ($status) {
            'پرداخت شده', 'paid' => 'paid',
            'در انتظار پرداخت', 'unpaid', 'pending' => 'unpaid',
            'پرداخت ناقص', 'partial' => 'partial',
            default => 'unpaid',
        };
    }

    private function resolveInstallStatusKey(string $status): string
    {
        return match ($status) {
            'نصب شده', 'installed' => 'installed',
            'در حال نصب', 'installing' => 'installing',
            default => 'pending',
        };
    }
}

