<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Webhooks\BrevoMailWebhookController;
use App\Http\Controllers\Webhooks\SesMailWebhookController;
use Illuminate\Support\Facades\Route;

// Mail provider delivery reports. Stateless, authenticated per provider, and
// disabled (404) until their secret or topic is configured.
Route::middleware('throttle:240,1')->prefix('webhooks/mail')->group(function (): void {
    Route::post('/brevo', BrevoMailWebhookController::class)->name('webhooks.mail.brevo');
    Route::post('/ses', SesMailWebhookController::class)->name('webhooks.mail.ses');
});
