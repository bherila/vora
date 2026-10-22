<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Events\EventAccess;
use App\Services\Events\EventJoining;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventJoinController extends Controller
{
    public function start(Request $request, string $ulid, EventJoining $joining): RedirectResponse
    {
        // Do not bind or look up an Event before admission. Hidden and missing
        // links have identical login/verification/approval behavior.
        $joining->remember($request, $ulid);

        return redirect()->route($request->user() ? 'events.join.continue' : 'login');
    }

    public function continue(Request $request, EventJoining $joining, EventAccess $access): RedirectResponse
    {
        $ulid = $joining->ulid($request);
        if ($ulid === null) {
            return redirect()->route('events.index');
        }
        if ($request->user()->force_change_pw) {
            return redirect()->route('user.settings');
        }

        $joining->forget($request);
        $event = $access->visibleTo(Event::query(), $request->user())->where('ulid', $ulid)->first();
        abort_if($event === null, 404, 'Not found.');

        return redirect()->route('events.index', ['event_ulid' => $event->ulid]);
    }
}
