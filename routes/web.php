<?php

use App\Http\Controllers\AuthController;
use App\Http\Middleware\EnsureActiveAccount;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});

Route::middleware(['auth', EnsureActiveAccount::class])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::view('/account', 'auth.account')->name('account');
    Route::put('/account/password', [AuthController::class, 'updatePassword'])->middleware('throttle:5,1')->name('password.update');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
