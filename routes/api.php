<?php

use App\Http\Controllers\Api\AdminBannerController;
use App\Http\Controllers\Api\AdminCardPaymentController;
use App\Http\Controllers\Api\AdminConsoleController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminCouponController;
use App\Http\Controllers\Api\AdminGameController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommonController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WishlistController;
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
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('api.auth.login');

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

// Card-to-card receipts are private. The signed, short-lived link is the permission.
Route::get('/receipts/{payment}', [OrderController::class, 'showReceipt'])->middleware('signed:relative')->name('api.receipts.show');

/*
|--------------------------------------------------------------------------
| Protected Customer Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // Coupons
    Route::post('/coupons/check', [CouponController::class, 'check'])->middleware('throttle:financial')->name('api.coupons.check');

    // Wishlist
    Route::get('/wishlist', [WishlistController::class, 'index'])->name('api.wishlist.index');
    Route::post('/wishlist/{id}', [WishlistController::class, 'store'])->name('api.wishlist.store');
    Route::delete('/wishlist/{id}', [WishlistController::class, 'destroy'])->name('api.wishlist.destroy');

    // Wallet
    Route::get('/wallet', [WalletController::class, 'index'])->name('api.wallet.index');
    Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('api.wallet.transactions');
    Route::post('/wallet/charge', [WalletController::class, 'charge'])->middleware('throttle:financial')->name('api.wallet.charge');
    Route::get('/wallet/charges', [WalletController::class, 'cardCharges'])->name('api.wallet.charges');
    Route::post('/wallet/charges/{id}/receipt', [WalletController::class, 'uploadChargeReceipt'])->whereNumber('id')->middleware('throttle:financial')->name('api.wallet.charges.receipt');

    // Orders & Checkout
    Route::get('/orders', [OrderController::class, 'index'])->name('api.orders.index');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('api.orders.show');
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->name('api.orders.cancel');
    Route::post('/orders/{id}/receipt', [OrderController::class, 'uploadReceipt'])->middleware('throttle:financial')->name('api.orders.receipt');
    Route::get('/payment-methods', [OrderController::class, 'paymentMethods'])->name('api.payment-methods');
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
        Route::get('/orders', [AdminOrderController::class, 'orders'])->name('api.admin.orders');
        Route::get('/orders/{id}', [AdminOrderController::class, 'orderDetail'])->name('api.admin.orders.show');
        Route::patch('/orders/{id}', [AdminOrderController::class, 'updateOrder'])->name('api.admin.orders.update');
        Route::match(['patch', 'post'], '/orders/{id}/status', [AdminOrderController::class, 'updateOrderStatus'])->name('api.admin.orders.status');
        Route::patch('/orders/{id}/payment-status', [AdminOrderController::class, 'updatePaymentStatus'])->name('api.admin.orders.payment-status');
        Route::post('/orders/{id}/receipt/approve', [AdminCardPaymentController::class, 'approve'])->name('api.admin.orders.receipt.approve');
        Route::post('/orders/{id}/receipt/reject', [AdminCardPaymentController::class, 'reject'])->name('api.admin.orders.receipt.reject');
        Route::patch('/orders/{id}/items/{itemId}/installation-status', [AdminOrderController::class, 'updateItemInstallationStatus'])->name('api.admin.orders.items.installation');

        // Users Management
        Route::get('/users', [AdminUserController::class, 'users'])->name('api.admin.users');
        Route::post('/users', [AdminUserController::class, 'storeUser'])->name('api.admin.users.store');
        Route::get('/users/{id}', [AdminUserController::class, 'userDetail'])->name('api.admin.users.show');
        Route::patch('/users/{id}', [AdminUserController::class, 'updateUser'])->name('api.admin.users.update');
        Route::delete('/users/{id}', [AdminUserController::class, 'deleteUser'])->name('api.admin.users.delete');

        // Games Management
        Route::get('/games', [AdminGameController::class, 'games'])->name('api.admin.games.index');
        Route::get('/games/{id}', [AdminGameController::class, 'gameDetail'])->name('api.admin.games.show');
        Route::post('/games', [AdminGameController::class, 'storeGame'])->name('api.admin.games.store');
        Route::match(['put', 'patch', 'post'], '/games/{id}', [AdminGameController::class, 'updateGame'])->name('api.admin.games.update');
        Route::patch('/games/{id}/pin', [AdminGameController::class, 'pinGame'])->name('api.admin.games.pin');
        Route::post('/games/{id}/screenshots', [AdminGameController::class, 'uploadScreenshots'])->name('api.admin.games.screenshots');
        Route::delete('/games/{id}', [AdminGameController::class, 'deleteGame'])->name('api.admin.games.delete');

        // Consoles Management
        Route::get('/consoles', [AdminConsoleController::class, 'index'])->name('api.admin.consoles.index');
        Route::post('/consoles', [AdminConsoleController::class, 'store'])->name('api.admin.consoles.store');
        Route::match(['put', 'patch', 'post'], '/consoles/{platform}', [AdminConsoleController::class, 'update'])->name('api.admin.consoles.update');
        Route::delete('/consoles/{platform}', [AdminConsoleController::class, 'destroy'])->name('api.admin.consoles.delete');

        // Coupons
        Route::get('/coupons', [AdminCouponController::class, 'index'])->name('api.admin.coupons.index');
        Route::post('/coupons', [AdminCouponController::class, 'store'])->name('api.admin.coupons.store');
        Route::patch('/coupons/{coupon}', [AdminCouponController::class, 'update'])->whereNumber('coupon')->name('api.admin.coupons.update');
        Route::delete('/coupons/{coupon}', [AdminCouponController::class, 'destroy'])->whereNumber('coupon')->name('api.admin.coupons.destroy');

        // Card-to-card wallet top-ups
        Route::get('/wallet-charges', [AdminCardPaymentController::class, 'walletCharges'])->name('api.admin.wallet-charges');
        Route::post('/wallet-charges/{id}/approve', [AdminCardPaymentController::class, 'approveWalletCharge'])->whereNumber('id')->name('api.admin.wallet-charges.approve');
        Route::post('/wallet-charges/{id}/reject', [AdminCardPaymentController::class, 'rejectWalletCharge'])->whereNumber('id')->name('api.admin.wallet-charges.reject');

        // Announcements
        Route::get('/notifications', [AdminNotificationController::class, 'index'])->name('api.admin.notifications.index');
        Route::post('/notifications', [AdminNotificationController::class, 'store'])->name('api.admin.notifications.store');

        // Financial & Banners
        Route::get('/transactions', [AdminController::class, 'transactions'])->name('api.admin.transactions');
        Route::get('/banners', [AdminBannerController::class, 'banners'])->name('api.admin.banners');
        Route::post('/banners', [AdminBannerController::class, 'storeBanner'])->name('api.admin.banners.store');
        Route::delete('/banners/{id}', [AdminBannerController::class, 'deleteBanner'])->name('api.admin.banners.delete');
    });
