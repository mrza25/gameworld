<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RegisteredUser;
use App\Http\Controllers\LoginController;



Route::get('/', fn () => view('welcome'));
Route::get('/register', [RegisteredUser::class, 'create'])->name('register')->middleware('guest');
Route::post('/register', [RegisteredUser::class, 'store'])->name('register.store')->middleware('guest');

Route::get('/login', [LoginController::class, 'create'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'store'])->middleware('guest');

Route::post('/logout', [LoginController::class, 'destroy'])->name('logout')->middleware('auth');
