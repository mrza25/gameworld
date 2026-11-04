<?php

use App\Http\Controllers\Api\AdminConsoleController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommonController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System & Health Check Routes
|--------------------------------------------------------------------------
*/
Route::get('/ping', fn () => response()->json([
    'ok' => true,
    'status' => 'pong',
    'timestamp' => now()->toIso8601String(),
]))->name('api.ping');

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $dbStatus = 'connected';
    } catch (Throwable $e) {
        $dbStatus = 'disconnected';
    }

    return response()->json([
        'ok' => true,
        'status' => 'healthy',
        'service' => 'gameworld-api',
        'version' => '1.0.0',
        'database' => $dbStatus,
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('api.health');

/*
|--------------------------------------------------------------------------
| Public Catalog & Platform Routes
|--------------------------------------------------------------------------
*/
Route::get('/consoles', [ProductController::class, 'consoles'])->name('api.consoles');
Route::get('/genres', [ProductController::class, 'genres'])->name('api.genres');
Route::get('/products', [ProductController::class, 'index'])->name('api.products.index');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('api.products.show');
Route::get('/games', [ProductController::class, 'index'])->name('api.games.index');
Route::get('/games/{slug}', [ProductController::class, 'show'])->name('api.games.show');
Route::get('/banners', [CommonController::class, 'banners'])->name('api.banners');

/*
|--------------------------------------------------------------------------
| Authentication & OTP Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/check-phone', [AuthController::class, 'checkPhone'])->middleware('throttle:auth')->name('api.auth.check-phone');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('api.auth.register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('api.auth.login');

    // OTP endpoints
    Route::post('/send-otp', [AuthController::class, 'sendOtp'])->middleware('throttle:auth')->name('api.auth.send-otp');
    Route::post('/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:auth');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth')->name('api.auth.verify-otp');
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth');
    Route::post('/login-with-otp', [AuthController::class, 'loginWithOtp'])->middleware('throttle:auth')->name('api.auth.login-with-otp');
    Route::post('/otp-login', [AuthController::class, 'loginWithOtp'])->middleware('throttle:auth');
    Route::post('/login-otp', [AuthController::class, 'loginWithOtp'])->middleware('throttle:auth');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth')->name('api.auth.reset-password');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        // Customer Profile
        Route::patch('/profile', [AuthController::class, 'updateProfile'])->name('api.auth.profile');
        Route::post('/phone/otp', [AuthController::class, 'sendPhoneChangeOtp'])->middleware('throttle:auth')->name('api.auth.phone.otp');
        Route::post('/phone/verify', [AuthController::class, 'updatePhone'])->middleware('throttle:auth')->name('api.auth.phone.verify');
        Route::post('/profile/avatar', [AuthController::class, 'uploadAvatar'])->name('api.auth.avatar');
        Route::patch('/password', [AuthController::class, 'changePassword'])->name('api.auth.password');
    });
});

/*
|--------------------------------------------------------------------------
| Payment Gateway Callbacks
|--------------------------------------------------------------------------
|
| The gateway redirects the customer's browser here without an API token.
| Each payment is authenticated by verifying its transaction instead.
|
*/
Route::middleware('throttle:financial')->group(function () {
    Route::get('/wallet/callback', [WalletController::class, 'callback'])->name('api.wallet.callback');
    Route::get('/orders/payment/callback', [OrderController::class, 'callback'])->name('api.orders.callback');
});

/*
|--------------------------------------------------------------------------
| Protected Customer Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // Wallet
    Route::get('/wallet', [WalletController::class, 'index'])->name('api.wallet.index');
    Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('api.wallet.transactions');
    Route::post('/wallet/charge', [WalletController::class, 'charge'])->middleware('throttle:financial')->name('api.wallet.charge');

    // Orders & Checkout
    Route::get('/orders', [OrderController::class, 'index'])->name('api.orders.index');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('api.orders.show');
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->name('api.orders.cancel');
    Route::post('/orders/checkout', [OrderController::class, 'checkout'])->middleware('throttle:financial')->name('api.orders.checkout');
    Route::post('/orders', [OrderController::class, 'checkout'])->middleware('throttle:financial');
    Route::post('/checkout', [OrderController::class, 'checkout'])->middleware('throttle:financial');

    // Game Ratings & Reviews
    Route::post('/games/{id}/rate', [CommonController::class, 'rateGame'])->name('api.games.rate');
    Route::post('/products/{id}/rate', [CommonController::class, 'rateGame'])->name('api.products.rate');

    // Notifications
    Route::get('/notifications', [CommonController::class, 'notifications'])->name('api.notifications.index');
    Route::patch('/notifications/{id}/read', [CommonController::class, 'markNotificationAsRead'])->name('api.notifications.read');
    Route::post('/notifications/mark-all-read', [CommonController::class, 'markAllNotificationsAsRead'])->name('api.notifications.mark-all-read');
});

/*
|--------------------------------------------------------------------------
| Protected Administrative Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', AdminMiddleware::class])
    ->prefix('admin')
    ->group(function () {
        // Dashboard
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('api.admin.dashboard');
        Route::get('/dashboard-stats', [AdminController::class, 'dashboardStats'])->name('api.admin.dashboard-stats');

        // Orders Management
        Route::get('/orders', [AdminController::class, 'orders'])->name('api.admin.orders');
        Route::get('/orders/{id}', [AdminController::class, 'orderDetail'])->name('api.admin.orders.show');
        Route::patch('/orders/{id}', [AdminController::class, 'updateOrder'])->name('api.admin.orders.update');
        Route::match(['patch', 'post'], '/orders/{id}/status', [AdminController::class, 'updateOrderStatus'])->name('api.admin.orders.status');
        Route::patch('/orders/{id}/payment-status', [AdminController::class, 'updatePaymentStatus'])->name('api.admin.orders.payment-status');
        Route::patch('/orders/{id}/items/{itemId}/installation-status', [AdminController::class, 'updateItemInstallationStatus'])->name('api.admin.orders.items.installation');

        // Users Management
        Route::get('/users', [AdminController::class, 'users'])->name('api.admin.users');
        Route::post('/users', [AdminController::class, 'storeUser'])->name('api.admin.users.store');
        Route::get('/users/{id}', [AdminController::class, 'userDetail'])->name('api.admin.users.show');
        Route::patch('/users/{id}', [AdminController::class, 'updateUser'])->name('api.admin.users.update');
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser'])->name('api.admin.users.delete');

        // Games Management
        Route::get('/games', [AdminController::class, 'games'])->name('api.admin.games.index');
        Route::get('/games/{id}', [AdminController::class, 'gameDetail'])->name('api.admin.games.show');
        Route::post('/games', [AdminController::class, 'storeGame'])->name('api.admin.games.store');
        Route::match(['put', 'patch', 'post'], '/games/{id}', [AdminController::class, 'updateGame'])->name('api.admin.games.update');
        Route::patch('/games/{id}/pin', [AdminController::class, 'pinGame'])->name('api.admin.games.pin');
        Route::post('/games/{id}/screenshots', [AdminController::class, 'uploadScreenshots'])->name('api.admin.games.screenshots');
        Route::delete('/games/{id}', [AdminController::class, 'deleteGame'])->name('api.admin.games.delete');

        // Consoles Management
        Route::get('/consoles', [AdminConsoleController::class, 'index'])->name('api.admin.consoles.index');
        Route::post('/consoles', [AdminConsoleController::class, 'store'])->name('api.admin.consoles.store');
        Route::match(['put', 'patch', 'post'], '/consoles/{platform}', [AdminConsoleController::class, 'update'])->name('api.admin.consoles.update');
        Route::delete('/consoles/{platform}', [AdminConsoleController::class, 'destroy'])->name('api.admin.consoles.delete');

        // Announcements
        Route::get('/notifications', [AdminNotificationController::class, 'index'])->name('api.admin.notifications.index');
        Route::post('/notifications', [AdminNotificationController::class, 'store'])->name('api.admin.notifications.store');

        // Financial & Banners
        Route::get('/transactions', [AdminController::class, 'transactions'])->name('api.admin.transactions');
        Route::get('/banners', [AdminController::class, 'banners'])->name('api.admin.banners');
        Route::post('/banners', [AdminController::class, 'storeBanner'])->name('api.admin.banners.store');
        Route::delete('/banners/{id}', [AdminController::class, 'deleteBanner'])->name('api.admin.banners.delete');
    });
