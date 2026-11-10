<?php

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
