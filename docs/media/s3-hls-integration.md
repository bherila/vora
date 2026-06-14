# Media: s3-hls video integration

Uploaded videos are transcoded to HLS by the external **s3-hls** service, which
runs independently of this app and coordinates purely through R2 object
conventions — there is no shared database or API between them.

> This document covers the **application** side of the contract (how the app
> uploads, resolves playback, and deletes). For the **infrastructure** overview
> — bucket inventory, token scopes, and the transcoder host — see
> [`../s3-hls-integration.md`](../s3-hls-integration.md).

## Flow

1. The app uploads a source video to the **video-source** bucket under
   `<prefix>/<user-id>/<ulid>.<ext>`.
2. The transcoder polls that bucket on a schedule, transcodes new videos, and
   writes output to the **HLS-output** bucket.
3. For each source it writes a mapping object:

   ```
   mappings/<source-object-key>.json
   ```

   containing (among other fields) an `hlsRoot` pointing at the master playlist,
   e.g. `by-id/<content-hash>/master.m3u8`.
4. To play a video, the app resolves the mapping (`HlsService::ensureResolved()`),
   caching the `contentId` on the media row:
   - **missing** → still `processing`;
   - **present** → `ready`, and the app exposes a `master_url` pointing at the
     authenticated playback proxy.

### Authenticated playback proxy

Playback does **not** expose the HLS bucket publicly. `MediaController@streamHls`
(`GET /api/media/{media}/hls/{path?}`, gated by `MediaPolicy@view`) serves it:

- **Manifests** (`.m3u8`) are fetched from the HLS bucket and returned inline
  with every child URI rewritten back through the proxy (so access stays gated
  and relative URIs resolve).
- **Segments / init** objects are **302-redirected** to short-lived presigned R2
  URLs, so R2 — not the app — carries the segment bandwidth.

The frontend points hls.js (or native HLS on Safari) at the `master_url` and
falls back to a signed URL for the original source file on error.

## Content-hash deduplication and deletion

The transcoder deduplicates output by **content hash**: multiple identical (or
lower-quality) source uploads can map to a single transcoded output. Therefore
the app must **never delete anything in the HLS-output bucket** — doing so could
break another upload that shares the same content.

On delete, the app removes only the **source** object (see
`MediaService::delete()`). The transcoder owns cleanup of transcoded output via
its own reference-counted pass, which only removes an output once no remaining
source maps to it.

> **Operational requirement:** that cleanup must be enabled on the transcoder
> (`CLEANUP_DELETED_SOURCES=true` in its environment). If it is off, transcoded
> output is retained indefinitely after sources are deleted.

## Playback notes

`resources/js/media/HlsVideoPlayer.tsx` plays the adaptive HLS stream via hls.js
(native HLS on Safari/iOS) against the proxy `master_url`, falling back to the
signed source URL on unrecoverable error. The HLS bucket needs a CORS policy
allowing `GET`/`HEAD` so the browser can fetch the 302-redirected segments.
