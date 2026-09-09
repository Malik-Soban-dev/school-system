<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PortalController;
use App\Http\Middleware\EnsureActiveAccount;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitation.accept');
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});

Route::middleware(['auth', EnsureActiveAccount::class])->group(function () {
    Route::get('/portal/meta', [PortalController::class, 'meta']);
    Route::get('/portal/users', [PortalController::class, 'users']);
    Route::put('/portal/users/{user}', [PortalController::class, 'updateUser']);
    Route::post('/portal/invitations', [InvitationController::class, 'store']);
    Route::get('/portal/invitations', [InvitationController::class, 'index']);
    Route::delete('/portal/invitations/{id}', [InvitationController::class, 'revoke'])->whereNumber('id');
    Route::put('/portal/settings', [PortalController::class, 'settings']);
    Route::put('/portal/tutorial', [PortalController::class, 'tutorial']);
    Route::put('/portal/attendance/batch', [PortalController::class, 'attendanceBatch']);
    Route::get('/portal/attendance/roster', [PortalController::class, 'attendanceRoster']);
    Route::get('/portal/audit', [PortalController::class, 'audit']);
    Route::get('/portal/records/{module}', [PortalController::class, 'index']);
    Route::post('/portal/records/{module}', [PortalController::class, 'save']);
    Route::put('/portal/records/{module}/{id}', [PortalController::class, 'save'])->whereNumber('id');
    Route::get('/reports/{module}/{id}', [PortalController::class, 'report'])->whereNumber('id')->name('record.report');
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::view('/account', 'auth.account')->name('account');
    Route::put('/account/password', [AuthController::class, 'updatePassword'])->middleware('throttle:5,1')->name('password.update');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
