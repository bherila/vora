<?php

use App\Http\Controllers\Admin\AdminAuditController;
use App\Http\Controllers\Admin\AdminInterestController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\InterestController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Home page (public).
Route::get('/', fn () => view('welcome'))->name('home');

/*
|--------------------------------------------------------------------------
| Guest auth pages
|--------------------------------------------------------------------------
| The bherila/auth-laravel package owns the JSON APIs (password reset, passkeys,
| 2FA); these routes own the pages and the password-login entrypoint.
*/
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::post('/api/auth/register', [RegisterController::class, 'store']);

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::post('/api/auth/login', [LoginController::class, 'store']);
    Route::get('/login/two-factor/{token}', [LoginController::class, 'showTwoFactor'])->name('login.two-factor');

    Route::get('/forgot-password', fn () => view('auth.forgot-password'))->name('password.request');
    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset-password', ['token' => $token]))->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| Authenticated (no approval gate — these are the gate pages themselves)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')->name('verification.send');

    Route::patch('/api/account', [ProfileController::class, 'update']);

    Route::get('/pending-approval', fn () => view('auth.pending-approval'))->name('approval.pending');
    Route::get('/user/settings', fn () => view('user.settings'))->name('user.settings');

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

/*
|--------------------------------------------------------------------------
| Authenticated + verified + approved app area
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'approved'])->group(function () {
    Route::get('/dashboard', fn () => view('dashboard'))->name('dashboard');
    Route::get('/interests', [InterestController::class, 'index'])->name('interests');
});

/*
|--------------------------------------------------------------------------
| Admin (approved admins only)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'approved', 'can:admin-only'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('users');
    Route::get('/audit-log', [AdminAuditController::class, 'index'])->name('audit-log');
    Route::get('/interests', [AdminInterestController::class, 'index'])->name('interests');
});

// Admin JSON API — session-authenticated (web middleware), admin-gated. The
// admin-only ability already enforces the full access model (admin + approved +
// not disabled) and returns a clean 403 for JSON callers.
Route::middleware(['auth', 'approved', 'can:admin-only'])->prefix('api/admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'apiIndex']);
    Route::post('/users/{user}/approve', [AdminUserController::class, 'approve']);
    Route::patch('/users/{user}', [AdminUserController::class, 'update']);
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);

    Route::get('/interests', [AdminInterestController::class, 'apiIndex']);
    Route::post('/interests', [AdminInterestController::class, 'store']);
    Route::put('/interests/{interest}', [AdminInterestController::class, 'update']);
    Route::delete('/interests/{interest}', [AdminInterestController::class, 'destroy']);
    Route::get('/interest-requests', [AdminInterestController::class, 'apiRequestIndex']);
    Route::put('/interest-requests/{interestRequest}', [AdminInterestController::class, 'updateRequest']);
    Route::delete('/interest-requests/{interestRequest}', [AdminInterestController::class, 'destroyRequest']);
    Route::post('/interest-requests/{interestRequest}/approve', [AdminInterestController::class, 'approveRequest']);
    Route::post('/interest-requests/{interestRequest}/reject', [AdminInterestController::class, 'rejectRequest']);
});

Route::middleware(['auth', 'approved'])->prefix('api/interests')->group(function () {
    Route::get('/', [InterestController::class, 'apiIndex']);
    Route::post('/{interest}/rate', [InterestController::class, 'rate']);
    Route::delete('/{interest}/rate', [InterestController::class, 'destroyRate']);
    Route::post('/request', [InterestController::class, 'requestNew']);
});
