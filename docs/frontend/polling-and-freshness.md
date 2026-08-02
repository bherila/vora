# Polling and freshness

Vora deploys to **shared cPanel hosting**. There are no WebSockets, no
long-running workers, no containers, and no frequent scheduled jobs. Surfaces
that need to stay fresh do so by **client-side polling against conditional
responses**.

This is intentionally eventual, not real-time. A delay measured in tens of
seconds is acceptable everywhere it is currently used.

## Use the existing hook

There is one poller: `resources/js/lib/useAdaptivePolling.ts`. It handles:

- pausing when the document is hidden or the browser is offline;
- immediate refresh on regained focus, visibility, and reconnect;
- non-overlapping requests (a poll in flight blocks the next);
- jitter, so many clients do not synchronize;
- exponential backoff on failure, capped, resetting on success;
- bounding concurrent pollers by `group` / `maxGroupPollers`.

**Do not write a second poller.** If a surface needs behaviour the hook lacks,
extend the hook.

```ts
const { pollNow } = useAdaptivePolling({
  enabled: threadIsOpen,
  intervalMs: COMMENT_THREAD_POLL_MS,
  onPoll: refresh,
});
```

Interval constants live beside the hook. Current cadences:

| Surface | Constant | Interval |
| --- | --- | --- |
| Active chat thread | `ACTIVE_THREAD_POLL_MS` | 12s |
| Comment thread | `COMMENT_THREAD_POLL_MS` | 20s |
| Chat inbox | `INBOX_POLL_MS` | 45s |

## Poll narrowly

Only poll what the user is actually looking at. An expanded thread polls; a
collapsed one does not. A surface with many potentially-pollable cards must not
multiply requests — bound the number of concurrent pollers to the ones the user
has actually opened or interacted with. A feed page can hold dozens of comment
threads, so they share a `group` capped at three live pollers; the rest stay
mounted and refresh on interaction.

Set `enabled: false` rather than unmounting-and-remounting to pause.

## Conditional responses

A polled endpoint returns an **ETag** over a cheap monotonic revision, so an
unchanged resource costs a `304` and no payload. `ChatController::sync` is the
reference implementation:

```php
$token = hash_hmac('sha256', $scope.':'.$revision.':'.$viewerId, (string) config('app.key'));
$etag = '"'.$token.'"';

if ($request->header('If-None-Match') === $etag) {
    return response()->json(status: 304)->withHeaders(['ETag' => $etag]);
}

return response()->json([...])->withHeaders([
    'ETag' => $etag,
    'Cache-Control' => 'private, no-cache',
]);
```

Rules:

- **Scope the ETag to the viewer.** Two users must never share a token for the
  same resource.
- **Always `Cache-Control: private`.** These payloads are per-viewer and must not
  land in a shared cache.
- **Authorize before returning 304.** Access is rechecked on every request. A
  viewer who lost access gets 403 regardless of the ETag they present — the 304
  path must sit *after* the authorization check, never before it.
- **Bump the revision on every state change that affects the payload**, including
  admin moderation. A change that does not bump the revision leaves clients
  holding a stale ETag indefinitely; they will never refetch on their own.

Store the revision as a monotonic counter on the row that owns the resource
(`users.chat_sync_version`, `posts.comment_revision`). Do not derive it from
`MAX(updated_at)` — that costs the query you are trying to avoid.

A counter on the owning row tracks changes to the *resource*, not changes to the
**viewer's relationship** to it. Blocking an author, or that author's account
being disabled, alters what the payload should contain without touching the
counter, so an open thread can hold a valid ETag over stale rows until it
remounts. Acceptable today because those actions navigate. If a surface ever
needs to survive them in place, fold the viewer-side input into the ETag rather
than bumping every post's counter.

## Stop on inaccessible

`401`, `403`, and `404` mean the client should **clear cached data for that
resource and stop polling it**. Continuing to poll a resource the viewer has lost
access to leaks nothing, but it wastes requests and keeps stale content on screen.

## Throttle polled routes

A polled endpoint receives orders of magnitude more traffic than a click-driven
one, on hosting that will not absorb it. Apply a `throttle:` middleware sized to
the poll interval and the plausible number of concurrent pollers per user.

## Testing

Poller behaviour is tested with fake timers. The coverage bar for any new polled
surface:

- no polling while collapsed, hidden, or offline;
- polling resumes on visibility and online events;
- no overlapping request;
- unchanged revision produces a 304 and no state churn;
- local mutation triggers an immediate refresh;
- an inaccessible response clears data and stops the poller.
