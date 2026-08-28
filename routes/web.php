<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegisteredUser;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\ConsoleGameController;
use App\Http\Controllers\ConsoleStatusController;
use App\Http\Controllers\AdminConsoleController;
use App\Http\Middleware\AdminMiddleware;

/*
|--------------------------------------------------------------------------
| auth
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => view('welcome'));
Route::get('/register', [RegisteredUser::class, 'create'])->name('register')->middleware('guest');
Route::post('/register', [RegisteredUser::class, 'store'])->name('register.store')->middleware('guest');

Route::get('/login', [LoginController::class, 'create'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'store'])->middleware('guest');

Route::post('/logout', [LoginController::class, 'destroy'])->name('logout')->middleware('auth');

Route::get('/order', function () {
    return view('order');
})->name('order')->middleware('auth');



/*
|--------------------------------------------------------------------------
| Games
|--------------------------------------------------------------------------
*/

Route::get('/games', [GameController::class, 'index'])
    ->name('games.index');

Route::get('/games/create', [GameController::class, 'create'])
    ->name('games.create')
    ->middleware(['auth', AdminMiddleware::class]);

Route::post('/games', [GameController::class, 'store'])
    ->name('games.store')->middleware('auth');

Route::get('/games/{game}', [GameController::class, 'show'])
    ->name('games.show');

Route::get('/games/{game}/edit', [GameController::class, 'edit'])
    ->name('games.edit')->middleware('auth');

Route::patch('/games/{game}', [GameController::class, 'update'])
    ->name('games.update');

Route::delete('/games/{game}', [GameController::class, 'destroy'])
    ->name('games.destroy');


    // /*
    // |--------------------------------------------------------------------------
    // | Consoles
    // |--------------------------------------------------------------------------
    // */

    Route::middleware('auth')->group(function () {

    Route::resource('consoles', ConsoleController::class);

    Route::post(
        '/consoles/{console}/games',
        [ConsoleGameController::class, 'store']
    )->name('consoles.games.store');

    Route::delete(
        '/consoles/{console}/games/{game}',
        [ConsoleGameController::class, 'destroy']
    )->name('consoles.games.destroy');

    Route::patch(
        '/consoles/{console}/status',
        [ConsoleStatusController::class, 'update']
    )->name('consoles.status.update');
});

// /*
    // |--------------------------------------------------------------------------
    // | admin
    // |--------------------------------------------------------------------------
    // */
Route::middleware(['auth', AdminMiddleware::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        Route::get('/consoles', [
            AdminConsoleController::class,
            'index'
        ])->name('consoles.index');

        Route::get('/consoles/{console}', [
            AdminConsoleController::class,
            'show'
        ])->name('consoles.show');

});
