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
        $todayOrders = Order::whereDate('created_at', today())->count();
        $totalOrders = Order::count();
        $totalUsers = User::count();
        $totalGames = Game::count();

        $stats = [
            'total_orders' => $totalOrders,
            'today_orders' => $todayOrders,
            'total_users' => $totalUsers,
            'total_games' => $totalGames,
            'games' => $totalGames,
            'orders' => $totalOrders,
            'users' => $totalUsers,
            'pending_orders' => Order::where('status', OrderStatus::Pending)->count(),
            'processing_orders' => Order::where('status', OrderStatus::Processing)->count(),
            'completed_orders' => Order::where('status', OrderStatus::Completed)->count(),
        ];

        $recentOrders = Order::with(['user', 'items.game', 'items.gameVariant.platform'])
            ->latest()
            ->take(6)
            ->get()
            ->map(fn (Order $order) => $order->toApiArray());

        return response()->json([
            'ok' => true,
            'stats' => $stats,
            'recentOrders' => $recentOrders,
            'total_orders' => $totalOrders,
            'today_orders' => $todayOrders,
            'total_users' => $totalUsers,
            'total_games' => $totalGames,
        ]);
    }

    /**
     * Dedicated endpoint for admin dashboard widgets (dashstatus).
     */
    public function dashboardStats(): JsonResponse
    {
        $todayOrders = Order::whereDate('created_at', today())->count();
        $totalOrders = Order::count();
        $totalUsers = User::count();
        $totalGames = Game::count();

        $stats = [
            'total_orders' => $totalOrders,
            'today_orders' => $todayOrders,
            'total_users' => $totalUsers,
            'total_games' => $totalGames,
            'games' => $totalGames,
            'orders' => $totalOrders,
            'users' => $totalUsers,
            'pending_orders' => Order::where('status', OrderStatus::Pending)->count(),
            'processing_orders' => Order::where('status', OrderStatus::Processing)->count(),
            'completed_orders' => Order::where('status', OrderStatus::Completed)->count(),
        ];

        return response()->json([
            'ok' => true,
            'stats' => $stats,
            'total_orders' => $totalOrders,
            'today_orders' => $todayOrders,
            'total_users' => $totalUsers,
            'total_games' => $totalGames,
        ]);
    }

    /**
     * List all orders for administrative panel.
     */
    public function orders(Request $request): JsonResponse
    {
        $query = Order::with(['user', 'items.game', 'items.gameVariant.platform', 'payments'])->latest();

        if ($request->filled('status')) {
            $statusKey = $this->resolveStatusKey($request->status);
            $query->where('status', $statusKey);
        }

        if ($request->filled('payment_method')) {
            $pm = $request->payment_method;
            $query->whereHas('payments', function ($q) use ($pm) {
                $q->where('method', 'like', "%{$pm}%");
            });
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $cleanSearch = ltrim($search, '#');
            $query->where(function ($q) use ($search, $cleanSearch) {
                if (is_numeric($cleanSearch)) {
                    $q->where('id', (int) $cleanSearch);
                }
                $q->orWhereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                       ->orWhere('phonenumber', 'like', "%{$search}%");
                });
            });
        }

        $orders = $query->paginate($request->integer('per_page', 15));
        $orders->getCollection()->transform(fn (Order $order) => $order->toApiArray());

        $paginatorArray = $orders->toArray();
        $meta = [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
        ];
        $paginatorArray['meta'] = $meta;

        return response()->json([
            'ok' => true,
            'data' => $paginatorArray,
            'meta' => $meta,
            'orders' => $orders->items(),
        ]);
    }

    /**
     * Get single order details for admin.
     */
    public function orderDetail(int|string $id): JsonResponse
    {
        $cleanId = (int) ltrim((string) $id, '#');
        $order = Order::with(['user', 'items.game', 'items.gameVariant.platform', 'payments'])
            ->findOrFail($cleanId);

        $orderData = $order->toApiArray();

        return response()->json([
            'ok' => true,
            'data' => $orderData,
            'order' => $orderData,
        ]);
    }

    /**
     * Unified update for order (status, payment_status, install_statuses).
     */
    public function updateOrder(Request $request, int|string $id): JsonResponse
    {
        $cleanId = (int) ltrim((string) $id, '#');
        $order = Order::with('items')->findOrFail($cleanId);

        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'payment_status' => ['nullable', 'string'],
            'install_statuses' => ['nullable', 'array'],
            'install_statuses.*.item_id' => ['nullable', 'integer'],
            'install_statuses.*.id' => ['nullable', 'integer'],
            'install_statuses.*.install_status' => ['nullable', 'string'],
            'install_statuses.*.status' => ['nullable', 'string'],
        ]);

        $statusChanged = false;

        if (!empty($validated['status'])) {
            $statusKey = $this->resolveStatusKey($validated['status']);
            $oldStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;
            $order->update(['status' => $statusKey]);
            if ($oldStatus !== $statusKey) {
                $statusChanged = true;
            }
        }

        if (!empty($validated['payment_status'])) {
            $paymentKey = $this->resolvePaymentStatusKey($validated['payment_status']);
            $order->update(['payment_status' => $paymentKey]);
        }

        if (!empty($validated['install_statuses']) && is_array($validated['install_statuses'])) {
            foreach ($validated['install_statuses'] as $itemStatus) {
                $itemId = $itemStatus['item_id'] ?? $itemStatus['id'] ?? null;
                $newInstallStatus = $itemStatus['install_status'] ?? $itemStatus['status'] ?? null;
                if ($itemId && $newInstallStatus) {
                    $installKey = $this->resolveInstallStatusKey($newInstallStatus);
                    $item = $order->items()->find($itemId);
                    if ($item) {
                        $item->update([
                            'installation_status' => $installKey,
                            'installed_at' => $installKey === 'installed' ? now() : null,
                        ]);
                    }
                }
            }
            $order->syncStatusFromItems();
            $statusChanged = true;
        }

        $order->refresh();
        if ($statusChanged) {
            $order->user?->notify(new OrderStatusUpdated($order));
        }

        $orderData = $order->fresh(['user', 'items.game', 'items.gameVariant.platform', 'payments'])->toApiArray();

        return response()->json([
            'ok' => true,
            'message' => 'سفارش با موفقیت به‌روزرسانی شد.',
            'data' => $orderData,
            'order' => $orderData,
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
            $query->where(function ($sq) use ($q) {
                $sq->where('name', 'like', "%{$q}%")
                   ->orWhere('phonenumber', 'like', "%{$q}%");
            });
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $users = $query->paginate($request->integer('per_page', 15));
        $paginatorArray = $users->toArray();
        $meta = [
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(),
            'total' => $users->total(),
        ];
        $paginatorArray['meta'] = $meta;

        return response()->json([
            'ok' => true,
            'data' => $paginatorArray,
            'meta' => $meta,
            'users' => $users->items(),
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
     * List games for administrative management.
     */
    public function games(Request $request): JsonResponse
    {
        $query = Game::with(['variants.platform'])->latest();

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($sq) use ($q) {
                $sq->where('title', 'like', "%{$q}%")
                   ->orWhere('genre', 'like', "%{$q}%")
                   ->orWhere('developer', 'like', "%{$q}%");
            });
        }

        if ($request->filled('genre')) {
            $query->where('genre', $request->genre);
        }

        if ($request->filled('status')) {
            $st = strtolower((string) $request->status);
            if ($st === 'available' || $st === '1' || $st === 'true') {
                $query->where('is_available', true);
            } elseif ($st === 'unavailable' || $st === '0' || $st === 'false') {
                $query->where('is_available', false);
            }
        }

        if ($request->filled('console')) {
            $consoleSlug = $request->console;
            $query->whereHas('variants.platform', function ($pq) use ($consoleSlug) {
                $pq->where('slug', $consoleSlug)->orWhere('name', $consoleSlug);
            });
        }

        $games = $query->paginate($request->integer('per_page', 15));
        $games->getCollection()->transform(fn (Game $game) => $game->toCatalogArray());

        $paginatorArray = $games->toArray();
        $meta = [
            'current_page' => $games->currentPage(),
            'last_page' => $games->lastPage(),
            'per_page' => $games->perPage(),
            'total' => $games->total(),
        ];
        $paginatorArray['meta'] = $meta;

        return response()->json([
            'ok' => true,
            'data' => $paginatorArray,
            'meta' => $meta,
            'games' => $games->items(),
        ]);
    }

    /**
     * Add a game with platform variants.
     */
    public function storeGame(Request $request): JsonResponse
    {
        if (!$request->filled('title') && $request->filled('name')) {
            $request->merge(['title' => $request->input('name')]);
        }
        if (!$request->has('is_available') && $request->has('available')) {
            $request->merge(['is_available' => $request->boolean('available')]);
        }

        $rawVariants = $request->input('variants') ?? $request->input('platforms') ?? [];
        if (!empty($rawVariants) && is_array($rawVariants)) {
            $normalizedVariants = [];
            foreach ($rawVariants as $v) {
                $platformId = $v['platform_id'] ?? null;
                if (!$platformId && !empty($v['console'])) {
                    $platform = Platform::where('slug', $v['console'])
                        ->orWhere('name', $v['console'])
                        ->first();
                    $platformId = $platform?->id;
                }
                if ($platformId) {
                    $normalizedVariants[] = [
                        'platform_id' => $platformId,
                        'price' => $v['price'] ?? 1000000,
                        'size' => $v['size'] ?? 50.0,
                        'is_available' => $v['is_available'] ?? $v['available'] ?? true,
                    ];
                }
            }
            if (!empty($normalizedVariants)) {
                $request->merge(['variants' => $normalizedVariants]);
            }
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:games,slug'],
            'genre' => ['required', 'string', 'max:100'],
            'developer' => ['nullable', 'string', 'max:100'],
            'release_date' => ['nullable', 'date'],
            'releaseDate' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'age_rating' => ['nullable', 'integer', 'min:3', 'max:21'],
            'image' => ['nullable'],
            'screenshots' => ['nullable'],
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

        $screenshots = [];
        if ($request->hasFile('screenshots')) {
            foreach ($request->file('screenshots') as $file) {
                $screenshots[] = $file->store('screenshots', 'public');
            }
        } elseif (is_array($request->input('screenshots'))) {
            $screenshots = $request->input('screenshots');
        }

        $slug = $validated['slug'] ?? Str::slug($validated['title']);
        if (Game::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $releaseDate = $validated['release_date'] ?? $validated['releaseDate'] ?? now()->format('Y-m-d');

        $game = Game::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'genre' => $validated['genre'],
            'developer' => $validated['developer'] ?? 'PlayStation Studios',
            'release_date' => $releaseDate,
            'description' => $validated['description'] ?? null,
            'age_rating' => $validated['age_rating'] ?? 16,
            'image' => $imagePath,
            'screenshots' => $screenshots,
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

        if (!$request->filled('title') && $request->filled('name')) {
            $request->merge(['title' => $request->input('name')]);
        }
        if (!$request->has('is_available') && $request->has('available')) {
            $request->merge(['is_available' => $request->boolean('available')]);
        }

        $rawVariants = $request->input('variants') ?? $request->input('platforms') ?? null;
        if (!empty($rawVariants) && is_array($rawVariants)) {
            $normalizedVariants = [];
            foreach ($rawVariants as $v) {
                $platformId = $v['platform_id'] ?? null;
                if (!$platformId && !empty($v['console'])) {
                    $platform = Platform::where('slug', $v['console'])
                        ->orWhere('name', $v['console'])
                        ->first();
                    $platformId = $platform?->id;
                }
                if ($platformId) {
                    $normalizedVariants[] = [
                        'platform_id' => $platformId,
                        'price' => $v['price'] ?? 1000000,
                        'size' => $v['size'] ?? 50.0,
                        'is_available' => $v['is_available'] ?? $v['available'] ?? true,
                    ];
                }
            }
            if (!empty($normalizedVariants)) {
                $request->merge(['variants' => $normalizedVariants]);
            }
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'genre' => ['nullable', 'string', 'max:100'],
            'developer' => ['nullable', 'string', 'max:100'],
            'release_date' => ['nullable', 'date'],
            'releaseDate' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'age_rating' => ['nullable', 'integer', 'min:3', 'max:21'],
            'image' => ['nullable'],
            'screenshots' => ['nullable'],
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

        if ($request->hasFile('screenshots')) {
            $newScreens = [];
            foreach ($request->file('screenshots') as $file) {
                $newScreens[] = $file->store('screenshots', 'public');
            }
            $game->screenshots = array_merge((array) ($game->screenshots ?? []), $newScreens);
        } elseif (is_array($request->input('screenshots'))) {
            $game->screenshots = $request->input('screenshots');
        }

        if (isset($validated['title'])) $game->title = $validated['title'];
        if (isset($validated['genre'])) $game->genre = $validated['genre'];
        if (isset($validated['developer'])) $game->developer = $validated['developer'];
        if (isset($validated['release_date'])) $game->release_date = $validated['release_date'];
        if (isset($validated['releaseDate'])) $game->release_date = $validated['releaseDate'];
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
     * Upload screenshots for a game.
     */
    public function uploadScreenshots(Request $request, int $id): JsonResponse
    {
        $game = Game::findOrFail($id);

        $request->validate([
            'screenshots' => ['required', 'array'],
            'screenshots.*' => ['image', 'max:4096'],
        ]);

        $currentScreenshots = (array) ($game->screenshots ?? []);
        foreach ($request->file('screenshots') as $file) {
            $currentScreenshots[] = $file->store('screenshots', 'public');
        }

        $game->update(['screenshots' => $currentScreenshots]);

        return response()->json([
            'ok' => true,
            'message' => 'اسکرین‌شات‌ها با موفقیت ذخیره شدند.',
            'screenshots' => array_map(fn ($p) => url('storage/' . ltrim($p, '/')), $currentScreenshots),
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

