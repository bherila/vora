<?php

use App\Http\Controllers\Auth\EmergencyLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Middleware\EmergencySignIn;
use BWH\Auth\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::prefix('emergency-login')->name('emergency.')->middleware([EmergencySignIn::class, 'guest:web'])->group(function (): void {
    Route::get('/', [EmergencyLoginController::class, 'show'])->name('show');
    Route::post('/', [LoginController::class, 'store'])->name('store');
    Route::get('/two-factor/{token}', [EmergencyLoginController::class, 'twoFactor'])->name('challenge');
    Route::middleware('throttle:6,1')->group(function (): void {
        Route::post('/verify', [TwoFactorController::class, 'verify'])->name('verify');
        Route::post('/resend', [TwoFactorController::class, 'resend'])->name('resend');
        Route::get('/confirm/{token}', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::post('/confirm/{token}', [TwoFactorController::class, 'confirmSubmit'])->name('two-factor.confirm.submit');
        Route::get('/report/{token}', [TwoFactorController::class, 'report'])->name('two-factor.report');
        Route::post('/report/{token}', [TwoFactorController::class, 'reportSubmit'])->name('two-factor.report.submit');
    });
});
