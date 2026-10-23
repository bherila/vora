<?php

use App\Http\Controllers\BulkTransferController;
use App\Http\Controllers\Events\EventAdminController;
use App\Http\Controllers\Events\EventController;
use App\Http\Controllers\Events\EventJoinController;
use App\Http\Controllers\Events\EventMediaController;
use App\Http\Controllers\Events\EventOrganizerController;
use App\Models\Event;
use App\Services\Events\EventAccess;
use Illuminate\Support\Facades\Route;

// Bind only events this account may access. Missing and hidden ULIDs share
// the same response, including when a deployment disables the Event module.
Route::bind('event', fn (string $ulid): Event => app(EventAccess::class)
    ->visibleTo(Event::query(), request()->user())->where('ulid', $ulid)->first()
    ?? abort(404, 'Not found.'));

Route::get('/events/join/{ulid}', [EventJoinController::class, 'start'])->whereUlid('ulid')
    ->middleware('module:events')->name('events.join');

Route::middleware(['auth', 'approved', 'module:events'])->group(function (): void {
    Route::get('/events/continue', [EventJoinController::class, 'continue'])->name('events.join.continue');
    Route::get('/events', [EventController::class, 'page'])->name('events.index');
    Route::get('/attendees', [EventController::class, 'page'])->name('events.attendees');
    Route::get('/attendees/{user}', [EventController::class, 'page'])->name('events.attendee');
    Route::get('/api/events', [EventController::class, 'index']);
    Route::get('/api/events/attendees', [EventController::class, 'attendees']);
    Route::get('/api/events/settings', [EventController::class, 'settings']);
    Route::get('/api/events/media', [EventMediaController::class, 'index']);
    Route::post('/api/events/media/bulk-save', [BulkTransferController::class, 'save'])->middleware(['module:library', 'throttle:10,1'])->name('bulk-transfer.events.save');
    Route::post('/api/events/media/bulk-downloads', [BulkTransferController::class, 'prepare'])->middleware('throttle:5,1')->name('bulk-transfer.events.prepare');
    Route::get('/api/events/media/{ulid}/download', [EventMediaController::class, 'download'])->name('events.media.download');
    Route::get('/api/events/media/{ulid}', [EventMediaController::class, 'show']);
    Route::post('/api/events/{event:ulid}/uploads', [EventMediaController::class, 'upload']);
    Route::middleware('can:admin-only')->group(function (): void {
        Route::get('/events/organizer', [EventOrganizerController::class, 'page'])->name('events.organizer');
        Route::get('/api/admin/events/attendees', [EventOrganizerController::class, 'index']);
        Route::put('/api/admin/events/attendees/{user}/access', [EventOrganizerController::class, 'access']);
        Route::post('/api/admin/events', [EventAdminController::class, 'store']);
        Route::patch('/api/admin/events/{event:ulid}', [EventAdminController::class, 'update']);
        Route::put('/api/admin/events/settings', [EventAdminController::class, 'settings'])->middleware('module:favorites');
    });
});
