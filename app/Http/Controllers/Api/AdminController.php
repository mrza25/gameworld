<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Order;
use App\Models\Platform;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\OrderStatusUpdated;
use App\OrderStatus;
use App\PaymentStatus;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function __construct(
        private WalletService $walletService
    ) {}

    /**
     * Admin Dashboard statistics and recent orders.
     */
    public function dashboard(): JsonResponse
    {
        $stats = $this->buildDashboardStats();

        $recentOrders = Order::with(['user', 'items.game', 'items.gameVariant.platform'])
            ->latest()
            ->take(6)
            ->get()
            ->map(fn (Order $order) => $order->toApiArray());

        return response()->json([
            'ok' => true,
            'stats' => $stats,
            'recentOrders' => $recentOrders,
            'total_orders' => $stats['total_orders'],
            'today_orders' => $stats['today_orders'],
            'total_users' => $stats['total_users'],
            'total_games' => $stats['total_games'],
        ]);
    }

    /**
     * Dedicated endpoint for admin dashboard widgets (dashstatus).
     */
    public function dashboardStats(): JsonResponse
    {
        $stats = $this->buildDashboardStats();

        return response()->json([
            'ok' => true,
            'stats' => $stats,
            'total_orders' => $stats['total_orders'],
            'today_orders' => $stats['today_orders'],
            'total_users' => $stats['total_users'],
            'total_games' => $stats['total_games'],
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

        $orders = $query->paginate($this->perPage($request));
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

        if (! empty($validated['status'])) {
            $statusKey = $this->resolveStatusKey($validated['status']);
            $oldStatus = $order->status instanceof \BackedEnum ? $order->status->value : $order->status;
            $order->update(['status' => $statusKey]);
            if ($oldStatus !== $statusKey) {
                $statusChanged = true;
            }
        }

        if (! empty($validated['payment_status'])) {
            $paymentKey = $this->resolvePaymentStatusKey($validated['payment_status']);
            $order->update(['payment_status' => $paymentKey]);
        }

        if (! empty($validated['install_statuses']) && is_array($validated['install_statuses'])) {
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

        $users = $query->paginate($this->perPage($request));
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
            'password' => Hash::make($validated['password']),
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
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phonenumber,'.$id],
            'password' => ['nullable', 'string', 'min:6'],
            'is_admin' => ['nullable', 'boolean'],
            'wallet_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

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
        $query = Game::query()->withCatalogStats()->latest();

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

        $games = $query->paginate($this->perPage($request));
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
     * Get single game details for admin.
     */
    public function gameDetail(int|string $id): JsonResponse
    {
        $game = Game::with(['variants.platform'])
            ->where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', (string) $id)
            ->firstOrFail();

        $data = $game->toCatalogArray();

        return response()->json([
            'ok' => true,
            'data' => $data,
            'game' => $data,
            'product' => $data,
        ]);
    }

    /**
     * Add a game with platform variants.
     */
    public function storeGame(Request $request): JsonResponse
    {
        if (! $request->filled('title') && $request->filled('name')) {
            $request->merge(['title' => $request->input('name')]);
        }
        if (! $request->filled('developer') && $request->filled('brand')) {
            $request->merge(['developer' => $request->input('brand')]);
        }
        if (! $request->filled('age_rating') && $request->filled('age')) {
            $request->merge(['age_rating' => (int) $request->input('age')]);
        }
        if (! $request->filled('release_date') && $request->filled('releaseDate')) {
            $request->merge(['release_date' => $request->input('releaseDate')]);
        }
        if (! $request->has('is_available') && $request->has('available')) {
            $request->merge(['is_available' => $request->boolean('available')]);
        }

        // Parse modes (array or string)
        $rawModes = $request->input('modes');
        if (is_string($rawModes)) {
            $rawModes = json_validate($rawModes) ? json_decode($rawModes, true) : array_map('trim', explode(',', $rawModes));
        }
        $modes = (! empty($rawModes) && is_array($rawModes)) ? array_values(array_filter($rawModes)) : ['تک‌نفره', 'آنلاین'];

        // Parse languages (array or string)
        $rawLanguages = $request->input('languages');
        if (is_string($rawLanguages)) {
            $rawLanguages = json_validate($rawLanguages) ? json_decode($rawLanguages, true) : array_map('trim', explode(',', $rawLanguages));
        }
        $languages = (! empty($rawLanguages) && is_array($rawLanguages)) ? array_values(array_filter($rawLanguages)) : ['انگلیسی', 'زیرنویس فارسی'];

        $rawVariants = $request->input('variants') ?? $request->input('platforms') ?? null;
        if (empty($rawVariants) && $request->filled('platform')) {
            $rawVariants = [$request->input('platform')];
        }
        if (empty($rawVariants) && $request->filled('console')) {
            $rawVariants = [$request->input('console')];
        }

        // Price, size, and platform resolution
        $basePrice = (float) (
            $request->input('price')
            ?? $request->input('base_price')
            ?? $request->input('basePrice')
            ?? $request->input('amount')
            ?? (is_array($rawVariants) && isset($rawVariants[0]['price']) ? $rawVariants[0]['price'] : null)
            ?? 1000000
        );

        $baseSize = (float) (
            $request->input('size')
            ?? $request->input('file_size')
            ?? $request->input('fileSize')
            ?? (is_array($rawVariants) && isset($rawVariants[0]['size']) ? $rawVariants[0]['size'] : null)
            ?? 50.0
        );

        $basePlatform = (string) (
            $request->input('platform')
            ?? $request->input('console')
            ?? (is_array($rawVariants) && isset($rawVariants[0]['console']) ? $rawVariants[0]['console'] : null)
            ?? (is_array($rawVariants) && isset($rawVariants[0]['platform']) ? $rawVariants[0]['platform'] : null)
            ?? 'PlayStation 5'
        );

        $normalizedVariants = [];
        if (! empty($rawVariants) && is_array($rawVariants)) {
            foreach ($rawVariants as $v) {
                if (is_string($v) || is_numeric($v)) {
                    $platform = Platform::where('slug', $v)
                        ->orWhere('name', $v)
                        ->orWhere('id', (int) $v)
                        ->first();
                    if ($platform) {
                        $isCopy = str_contains($platform->slug, 'copy') || $platform->slug === 'other';
                        $normalizedVariants[] = [
                            'platform_id' => $platform->id,
                            'price' => $isCopy ? round($basePrice * 0.35) : $basePrice,
                            'size' => $baseSize,
                            'is_available' => true,
                        ];
                    }
                } elseif (is_array($v)) {
                    $platformId = $v['platform_id'] ?? null;
                    if (! $platformId && ! empty($v['console'])) {
                        $platform = Platform::where('slug', $v['console'])
                            ->orWhere('name', $v['console'])
                            ->first();
                        $platformId = $platform?->id;
                    }
                    if (! $platformId && ! empty($v['platform'])) {
                        $platform = Platform::where('slug', $v['platform'])
                            ->orWhere('name', $v['platform'])
                            ->first();
                        $platformId = $platform?->id;
                    }
                    if (! $platformId && ! empty($v['slug'])) {
                        $platform = Platform::where('slug', $v['slug'])->first();
                        $platformId = $platform?->id;
                    }
                    if ($platformId) {
                        $normalizedVariants[] = [
                            'platform_id' => $platformId,
                            'price' => $v['price'] ?? $basePrice,
                            'size' => $v['size'] ?? $baseSize,
                            'is_available' => $v['is_available'] ?? $v['available'] ?? true,
                        ];
                    }
                }
            }
        }

        // If still empty, auto-populate for all 11 platforms
        if (empty($normalizedVariants)) {
            foreach (Platform::all() as $p) {
                $isCopy = str_contains($p->slug, 'copy') || $p->slug === 'other';
                $normalizedVariants[] = [
                    'platform_id' => $p->id,
                    'price' => $isCopy ? round($basePrice * 0.35) : $basePrice,
                    'size' => $baseSize,
                    'is_available' => true,
                ];
            }
        }

        $request->merge(['variants' => $normalizedVariants]);

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
            'cover' => ['nullable'],
            'poster' => ['nullable'],
            'screenshots' => ['nullable'],
            'is_available' => ['nullable', 'boolean'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.platform_id' => ['required', 'exists:platforms,id'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.size' => ['required', 'numeric', 'min:0'],
            'variants.*.is_available' => ['nullable', 'boolean'],
        ]);

        $imagePath = null;
        $imageFile = $request->file('image') ?? $request->file('cover') ?? $request->file('poster');
        if ($imageFile) {
            $imagePath = $imageFile->store('games', 'public');
        } elseif (is_string($request->input('image'))) {
            $imagePath = $request->input('image');
        } elseif (is_string($request->input('cover'))) {
            $imagePath = $request->input('cover');
        } elseif (is_string($request->input('poster'))) {
            $imagePath = $request->input('poster');
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
            $slug .= '-'.Str::random(4);
        }

        $releaseDate = $validated['release_date'] ?? $validated['releaseDate'] ?? now()->format('Y-m-d');

        $game = Game::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'genre' => $validated['genre'],
            'price' => $basePrice,
            'size' => $baseSize,
            'platform' => $basePlatform,
            'developer' => $validated['developer'] ?? 'PlayStation Studios',
            'release_date' => $releaseDate,
            'description' => $validated['description'] ?? null,
            'age_rating' => $validated['age_rating'] ?? 16,
            'modes' => $modes,
            'languages' => $languages,
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

        $catalogData = $game->fresh(['variants.platform'])->toCatalogArray();

        return response()->json([
            'ok' => true,
            'message' => 'بازی با موفقیت ایجاد شد.',
            'data' => $catalogData,
            'game' => $catalogData,
            'product' => $catalogData,
        ], 201);
    }

    /**
     * Update a game and its platform variants.
     */
    public function updateGame(Request $request, int|string $id): JsonResponse
    {
        $game = Game::with(['variants.platform'])
            ->where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', (string) $id)
            ->firstOrFail();

        if (! $request->filled('title') && $request->filled('name')) {
            $request->merge(['title' => $request->input('name')]);
        }
        if (! $request->filled('developer') && $request->filled('brand')) {
            $request->merge(['developer' => $request->input('brand')]);
        }
        if (! $request->filled('age_rating') && $request->filled('age')) {
            $request->merge(['age_rating' => (int) $request->input('age')]);
        }
        if (! $request->filled('release_date') && $request->filled('releaseDate')) {
            $request->merge(['release_date' => $request->input('releaseDate')]);
        }
        if (! $request->has('is_available') && $request->has('available')) {
            $request->merge(['is_available' => $request->boolean('available')]);
        }

        $rawVariants = $request->input('variants') ?? $request->input('platforms') ?? null;
        if (empty($rawVariants) && $request->filled('platform')) {
            $rawVariants = [$request->input('platform')];
        }
        if (empty($rawVariants) && $request->filled('console')) {
            $rawVariants = [$request->input('console')];
        }

        $basePrice = $request->filled('price')
            ? (float) $request->input('price')
            : (float) ($request->input('base_price') ?? $request->input('basePrice') ?? (is_array($rawVariants) && isset($rawVariants[0]['price']) ? $rawVariants[0]['price'] : $game->price));

        $baseSize = $request->filled('size')
            ? (float) $request->input('size')
            : (float) ($request->input('file_size') ?? $request->input('fileSize') ?? (is_array($rawVariants) && isset($rawVariants[0]['size']) ? $rawVariants[0]['size'] : $game->size));

        if (! empty($rawVariants) && is_array($rawVariants)) {
            $normalizedVariants = [];
            foreach ($rawVariants as $v) {
                if (is_string($v) || is_numeric($v)) {
                    $platform = Platform::where('slug', $v)
                        ->orWhere('name', $v)
                        ->orWhere('id', (int) $v)
                        ->first();
                    if ($platform) {
                        $isCopy = str_contains($platform->slug, 'copy') || $platform->slug === 'other';
                        $normalizedVariants[] = [
                            'platform_id' => $platform->id,
                            'price' => $isCopy ? round($basePrice * 0.35) : $basePrice,
                            'size' => $baseSize,
                            'is_available' => true,
                        ];
                    }
                } elseif (is_array($v)) {
                    $platformId = $v['platform_id'] ?? null;
                    if (! $platformId && ! empty($v['console'])) {
                        $platform = Platform::where('slug', $v['console'])
                            ->orWhere('name', $v['console'])
                            ->first();
                        $platformId = $platform?->id;
                    }
                    if (! $platformId && ! empty($v['platform'])) {
                        $platform = Platform::where('slug', $v['platform'])
                            ->orWhere('name', $v['platform'])
                            ->first();
                        $platformId = $platform?->id;
                    }
                    if (! $platformId && ! empty($v['slug'])) {
                        $platform = Platform::where('slug', $v['slug'])->first();
                        $platformId = $platform?->id;
                    }
                    if ($platformId) {
                        $normalizedVariants[] = [
                            'platform_id' => $platformId,
                            'price' => $v['price'] ?? $basePrice,
                            'size' => $v['size'] ?? $baseSize,
                            'is_available' => $v['is_available'] ?? $v['available'] ?? true,
                        ];
                    }
                }
            }
            if (! empty($normalizedVariants)) {
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
            'cover' => ['nullable'],
            'poster' => ['nullable'],
            'screenshots' => ['nullable'],
            'is_available' => ['nullable', 'boolean'],
            'variants' => ['nullable', 'array'],
            'variants.*.platform_id' => ['required_with:variants', 'exists:platforms,id'],
            'variants.*.price' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.size' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.is_available' => ['nullable', 'boolean'],
        ]);

        $imageFile = $request->file('image') ?? $request->file('cover') ?? $request->file('poster');
        if ($imageFile) {
            $game->image = $imageFile->store('games', 'public');
        } elseif (is_string($request->input('image'))) {
            $game->image = $request->input('image');
        } elseif (is_string($request->input('cover'))) {
            $game->image = $request->input('cover');
        } elseif (is_string($request->input('poster'))) {
            $game->image = $request->input('poster');
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

        if (isset($validated['title'])) {
            $game->title = $validated['title'];
        }
        if (isset($validated['genre'])) {
            $game->genre = $validated['genre'];
        }
        if (isset($validated['developer'])) {
            $game->developer = $validated['developer'];
        }
        if (isset($validated['release_date'])) {
            $game->release_date = $validated['release_date'];
        }
        if (isset($validated['releaseDate'])) {
            $game->release_date = $validated['releaseDate'];
        }
        if (isset($validated['description'])) {
            $game->description = $validated['description'];
        }
        if (isset($validated['age_rating'])) {
            $game->age_rating = $validated['age_rating'];
        }
        if (isset($validated['is_available'])) {
            $game->is_available = $validated['is_available'];
        }

        if ($request->has('price') || $basePrice > 0) {
            $game->price = $basePrice;
        }
        if ($request->has('size') || $baseSize > 0) {
            $game->size = $baseSize;
        }
        if ($request->filled('platform') || $request->filled('console')) {
            $game->platform = $request->input('platform') ?? $request->input('console');
        }

        if ($request->has('modes')) {
            $modes = $request->input('modes');
            if (is_string($modes)) {
                $modes = json_validate($modes) ? json_decode($modes, true) : array_map('trim', explode(',', $modes));
            }
            $game->modes = is_array($modes) ? array_values(array_filter($modes)) : null;
        }

        if ($request->has('languages')) {
            $languages = $request->input('languages');
            if (is_string($languages)) {
                $languages = json_validate($languages) ? json_decode($languages, true) : array_map('trim', explode(',', $languages));
            }
            $game->languages = is_array($languages) ? array_values(array_filter($languages)) : null;
        }

        $game->save();

        if (! empty($validated['variants'])) {
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
        } elseif ($request->has('price') || $request->has('size')) {
            // Sync updated price/size to all existing variants if no specific variants passed
            foreach ($game->variants as $variant) {
                $isCopy = str_contains($variant->platform?->slug ?? '', 'copy') || ($variant->platform?->slug === 'other');
                $price = $isCopy ? round($game->price * 0.35) : $game->price;
                $variant->update([
                    'price' => $price,
                    'size' => $game->size > 0 ? $game->size : $variant->size,
                ]);
            }
        }

        $catalogData = $game->fresh(['variants.platform'])->toCatalogArray();

        return response()->json([
            'ok' => true,
            'message' => 'بازی با موفقیت به‌روزرسانی شد.',
            'data' => $catalogData,
            'game' => $catalogData,
            'product' => $catalogData,
        ]);
    }

    /**
     * Upload screenshots for a game.
     */
    public function uploadScreenshots(Request $request, int|string $id): JsonResponse
    {
        $game = Game::where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', (string) $id)
            ->firstOrFail();

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
            'screenshots' => array_map(fn ($p) => url('storage/'.ltrim($p, '/')), $currentScreenshots),
        ]);
    }

    /**
     * Delete a game.
     */
    public function deleteGame(int|string $id): JsonResponse
    {
        $game = Game::where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('slug', (string) $id)
            ->firstOrFail();

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
        $transactions = WalletTransaction::with('wallet.user')
            ->latest()
            ->paginate($this->perPage($request, 20));

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
        $banners = DB::table('banners')
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

        Cache::forget(CommonController::BANNERS_CACHE_KEY);

        $id = DB::table('banners')->insertGetId([
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
        DB::table('banners')->where('id', $id)->delete();
        Cache::forget(CommonController::BANNERS_CACHE_KEY);

        return response()->json([
            'ok' => true,
            'message' => 'بنر با موفقیت حذف شد.',
        ]);
    }

    /**
     * Shared statistics for the admin dashboard endpoints.
     *
     * @return array<string, int>
     */
    private function buildDashboardStats(): array
    {
        $totalOrders = Order::count();
        $totalUsers = User::count();
        $totalGames = Game::count();

        $statusCounts = Order::query()
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total_orders' => $totalOrders,
            'today_orders' => Order::whereDate('created_at', today())->count(),
            'total_users' => $totalUsers,
            'total_games' => $totalGames,
            'games' => $totalGames,
            'orders' => $totalOrders,
            'users' => $totalUsers,
            'pending_orders' => (int) ($statusCounts[OrderStatus::Pending->value] ?? 0),
            'processing_orders' => (int) ($statusCounts[OrderStatus::Processing->value] ?? 0),
            'completed_orders' => (int) ($statusCounts[OrderStatus::Completed->value] ?? 0),
        ];
    }

    /**
     * Set a wallet to the given balance through the ledger so every admin
     * adjustment leaves an auditable wallet transaction.
     */
    private function adjustWalletBalance(User $user, float $targetBalance): void
    {
        $wallet = $this->walletService->getOrCreateWallet($user);
        $difference = round($targetBalance - (float) $wallet->balance, 2);

        if ($difference > 0) {
            $this->walletService->credit($user, $difference, 'admin-adjustment', 'Balance adjusted by admin');
        } elseif ($difference < 0) {
            $this->walletService->debit($user, abs($difference), 'admin-adjustment', 'Balance adjusted by admin');
        }
    }

    private function perPage(Request $request, int $default = 15): int
    {
        return min(max($request->integer('per_page', $default), 1), 100);
    }

    private function resolveStatusKey(string $status): string
    {
        return match ($status) {
            'در حال بررسی', 'pending' => OrderStatus::Pending->value,
            'در حال پردازش', 'تایید شده', 'processing', 'confirmed' => OrderStatus::Processing->value,
            'تکمیل شده', 'آماده تحویل', 'completed', 'ready' => OrderStatus::Completed->value,
            default => throw ValidationException::withMessages([
                'status' => 'وضعیت سفارش نامعتبر است.',
            ]),
        };
    }

    private function resolvePaymentStatusKey(string $status): string
    {
        return match ($status) {
            'پرداخت شده', 'paid' => PaymentStatus::Paid->value,
            'در انتظار پرداخت', 'unpaid', 'pending' => PaymentStatus::Unpaid->value,
            'پرداخت ناقص', 'partial' => PaymentStatus::Partial->value,
            'ناموفق', 'failed' => PaymentStatus::Failed->value,
            'مسترد شده', 'refunded' => PaymentStatus::Refunded->value,
            default => throw ValidationException::withMessages([
                'payment_status' => 'وضعیت پرداخت نامعتبر است.',
            ]),
        };
    }

    private function resolveInstallStatusKey(string $status): string
    {
        return match ($status) {
            'نصب شده', 'installed' => 'installed',
            'در حال نصب', 'installing' => 'installing',
            'در انتظار نصب', 'pending' => 'pending',
            default => throw ValidationException::withMessages([
                'installation_status' => 'وضعیت نصب نامعتبر است.',
            ]),
        };
    }
}
