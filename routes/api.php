<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Catalog & Platform Routes
|--------------------------------------------------------------------------
*/
Route::get('/consoles', [ProductController::class, 'consoles'])->name('api.consoles');
Route::get('/products', [ProductController::class, 'index'])->name('api.products.index');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('api.products.show');
Route::get('/games', [ProductController::class, 'index'])->name('api.games.index');
Route::get('/games/{slug}', [ProductController::class, 'show'])->name('api.games.show');

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/check-phone', [AuthController::class, 'checkPhone'])->middleware('throttle:auth')->name('api.auth.check-phone');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('api.auth.register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('api.auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    });
});

/*
|--------------------------------------------------------------------------
| Protected Customer Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // Wallet
    Route::get('/wallet', [WalletController::class, 'index'])->name('api.wallet.index');
    Route::post('/wallet/charge', [WalletController::class, 'charge'])->name('api.wallet.charge');
    Route::get('/wallet/callback', [WalletController::class, 'callback'])->name('api.wallet.callback');

    // Orders & Checkout
    Route::get('/orders', [OrderController::class, 'index'])->name('api.orders.index');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('api.orders.show');
    Route::post('/orders/checkout', [OrderController::class, 'checkout'])->name('api.orders.checkout');
    Route::get('/orders/payment/callback', [OrderController::class, 'callback'])->name('api.orders.callback');
});

/*
|--------------------------------------------------------------------------
| Protected Administrative Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', AdminMiddleware::class])
    ->prefix('admin')
    ->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('api.admin.dashboard');
        Route::get('/orders', [AdminController::class, 'orders'])->name('api.admin.orders');
        Route::get('/orders/{id}', [AdminController::class, 'orderDetail'])->name('api.admin.orders.show');
        Route::patch('/orders/{id}/status', [AdminController::class, 'updateOrderStatus'])->name('api.admin.orders.status');
        Route::patch('/orders/{id}/payment-status', [AdminController::class, 'updatePaymentStatus'])->name('api.admin.orders.payment-status');
        Route::patch('/orders/{id}/items/{itemId}/installation-status', [AdminController::class, 'updateItemInstallationStatus'])->name('api.admin.orders.items.installation');
        Route::get('/users', [AdminController::class, 'users'])->name('api.admin.users');
    });
