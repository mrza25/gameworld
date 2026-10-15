<?php

use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\OrderController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\CheckoutController;
/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
})->name('home');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {

    Route::get('/login', [
        AuthController::class,
        'showLogin'
    ])->name('login');

    Route::post('/login', [
        AuthController::class,
        'login'
    ])->middleware('throttle:auth')->name('login.store');

    Route::get('/register', [
        AuthController::class,
        'showRegister'
    ])->name('register');

    Route::post('/register', [
        AuthController::class,
        'register'
    ])->middleware('throttle:auth')->name('register.store');
});

Route::post('/logout', [
    AuthController::class,
    'logout'
])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Games
|--------------------------------------------------------------------------
|
| Games catalog is public.
| Only admins can create/edit games.
|
*/

Route::get('/games', [
    GameController::class,
    'index'
])->name('games.index');

Route::get('/games/{game}', [
    GameController::class,
    'show'
])->name('games.show');

/*
|--------------------------------------------------------------------------
| Customer Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
//NOTIFICATIONS
    Route::get('/notifications', [
    NotificationController::class,
    'index'
])->name('notifications.index');

Route::patch('/notifications/{notification}/read', [
    NotificationController::class,
    'markAsRead'
])->name('notifications.read');
    /*
    |--------------------------------------------------------------------------
    | Consoles - Temporary
    |--------------------------------------------------------------------------
    |
    | These routes are kept for now because the old Console views
    | still exist. Later we will remove the old Console architecture.
    |
    */

    Route::get('/consoles', [
        ConsoleController::class,
        'index'
    ])->name('consoles.index');

    Route::get('/consoles/create', [
        ConsoleController::class,
        'create'
    ])->name('consoles.create');

    Route::post('/consoles', [
        ConsoleController::class,
        'store'
    ])->name('consoles.store');

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */

    Route::get('/orders', [
        OrderController::class,
        'index'
    ])->name('orders.index');

    Route::get('/orders/create', [
        OrderController::class,
        'create'
    ])->name('orders.create');

    Route::post('/orders', [
        OrderController::class,
        'store'
    ])->name('orders.store');

    Route::get('/orders/{order}', [
        OrderController::class,
        'show'
    ])->name('orders.show');
//wallet
    Route::get('/wallet', [
        WalletController::class,
        'index'
    ])->name('wallet.index');

    Route::post('/wallet/charge', [
        WalletController::class,
        'charge'
    ])->name('wallet.charge');

    Route::get('/wallet/callback', [
        WalletController::class,
        'chargeCallback'
    ])->name('wallet.callback');
//checkout
Route::post('/checkout/gateway', [
    CheckoutController::class,
    'payWithGateway'
])->name('checkout.gateway');

Route::get('/payment/callback', [
    CheckoutController::class,
    'gatewayCallback'
])->name('payment.callback');
Route::get('/checkout', [
    CheckoutController::class,
    'index'
])->name('checkout.index');
Route::post('/checkout/wallet', [
    CheckoutController::class,
    'payWithWallet'
])->name('checkout.wallet');


});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth',
    AdminMiddleware::class,
])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        /* Game Management */

Route::get('/games', [
    GameController::class,
    'adminIndex'
])->name('games.index');

Route::get('/games/create', [
    GameController::class,
    'create'
])->name('games.create');

Route::post('/games', [
    GameController::class,
    'store'
])->name('games.store');

Route::get('/games/{game}/edit', [
    GameController::class,
    'edit'
])->name('games.edit');

Route::patch('/games/{game}', [
    GameController::class,
    'update'
])->name('games.update');

Route::delete('/games/{game}', [
    GameController::class,
    'destroy'
])->name('games.destroy');
        /*
        |--------------------------------------------------------------------------
        | Order Management
        |--------------------------------------------------------------------------
        */

        Route::get('/orders', [
            AdminOrderController::class,
            'index'
        ])->name('orders.index');

        Route::get('/orders/{order}', [
            AdminOrderController::class,
            'show'
        ])->name('orders.show');

        Route::patch('/orders/{order}/status', [
            AdminOrderController::class,
            'updateStatus'
        ])->name('orders.status.update');

        Route::patch('/orders/{order}/payment-status', [
            AdminOrderController::class,
            'updatePaymentStatus'
        ])->name('orders.payment-status.update');

        Route::patch('/orders/{order}/items/installation-status', [
            AdminOrderController::class,
            'updateItemInstallationStatus'
        ])->name('orders.items.installation-status.update');

        Route::get('/', [
    AdminDashboardController::class,
    'index'
])->name('dashboard');
    });
