<?php

use App\Http\Controllers\Location\AdminPlacesController;
use App\Http\Controllers\Location\LocationController;
use App\Http\Controllers\Location\NearbyPeopleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Location
|--------------------------------------------------------------------------
|
| Optional, city-level account locations from the local GeoNames copy, and
| people near them. Part of the profiles module; personas carry no location.
| Everything lives under /api/location so these routes never depend on their
| order relative to /api/users/{user}.
|
*/

Route::middleware(['auth', 'approved', 'module:profiles'])->prefix('api/location')->group(function (): void {
    Route::put('/', [LocationController::class, 'update'])->middleware('throttle:30,1');
    Route::get('/places', [LocationController::class, 'search'])->middleware('throttle:120,1');
    Route::post('/places/nearest', [LocationController::class, 'nearest'])->middleware('throttle:20,1');
    Route::get('/nearby', [NearbyPeopleController::class, 'index'])->middleware('throttle:60,1');
});

// Admin → City list: GeoNames dataset, imports (run by the scheduler) and status.
Route::middleware(['auth', 'approved', 'can:admin-only', 'module:profiles'])->group(function (): void {
    Route::get('/admin/places', [AdminPlacesController::class, 'page'])->name('admin.places');
    Route::prefix('api/admin/places')->group(function (): void {
        Route::get('/', [AdminPlacesController::class, 'show']);
        Route::put('/settings', [AdminPlacesController::class, 'updateSettings']);
        Route::post('/import', [AdminPlacesController::class, 'import'])->middleware('throttle:10,1');
        Route::delete('/import', [AdminPlacesController::class, 'cancel']);
    });
});
