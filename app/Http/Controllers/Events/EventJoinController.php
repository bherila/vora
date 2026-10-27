<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Auth\SignIn;
use App\Services\Events\EventAccess;
use App\Services\Events\EventJoining;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EventJoinController extends Controller
{
    public function start(Request $request, string $ulid, EventJoining $joining): RedirectResponse
    {
        // Do not bind or look up an Event before admission. Hidden and missing
        // links have identical login/verification/approval behavior.
        $joining->remember($request, $ulid);

        return redirect()->route($request->user() ? 'events.join.continue' : 'login');
    }

    public function continue(Request $request, EventJoining $joining, EventAccess $access): RedirectResponse|Response
    {
        $ulid = $joining->ulid($request);
        if ($ulid === null) {
            return redirect()->route('events.index');
        }
        if ($request->user()->force_change_pw && ! SignIn::usesProvider()) {
            return redirect()->route('user.settings');
        }

        $joining->forget($request);
        $event = $access->visibleTo(Event::query(), $request->user())->where('ulid', $ulid)->first();
        if ($event === null) {
            return response()->view('events.unavailable', [], 404);
        }

        return redirect()->route('events.index', ['event_ulid' => $event->ulid]);
    }
}
