<?php

namespace App\Services\Events;

use Illuminate\Http\Request;

/** A joining link remembers a destination; it never grants Event membership. */
class EventJoining
{
    private const SESSION_KEY = 'events.join_ulid';

    public function remember(Request $request, string $ulid): void
    {
        $request->session()->put(self::SESSION_KEY, $ulid);
    }

    public function ulid(Request $request): ?string
    {
        $ulid = $request->session()->get(self::SESSION_KEY);
        if (! config('instance.modules.events') || ! is_string($ulid)
            || ! preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $ulid)) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $ulid;
    }

    public function url(Request $request): ?string
    {
        $ulid = $this->ulid($request);

        return $ulid === null ? null : route('events.join', ['ulid' => $ulid], false);
    }

    /** @return array{eventJoin: array{continue_url: ?string}} */
    public function initialData(Request $request): array
    {
        return ['eventJoin' => ['continue_url' => $this->url($request)]];
    }

    public function forget(Request $request): void
    {
        $request->session()->forget([self::SESSION_KEY, 'url.intended']);
    }
}
