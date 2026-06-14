# s3-hls video integration

Status: **infrastructure provisioned; app feature not built yet.**

This document describes the storage + transcoding plumbing that backs vora's planned
media-upload feature. The R2 buckets, credentials, and the transcoder are already set up
and running; what remains is the application code (upload UI/API + HLS playback), which
will port the equivalent vetvet implementation.

> **Secrets:** no credentials live in this repo. The R2 keys live only in the (gitignored)
> `.env` on the app host and on the transcoder host. This file references env-var **names**
> and token **scopes** only.

## What it does

vora will let users upload videos. Rather than stream the single uploaded original (slow to
start, fixed quality), an out-of-band transcoder produces an adaptive-bitrate **HLS** ladder
(H.264/AAC, 360/480/720/1080p, capped at source resolution) that the app plays back.

```
 vora (Laravel)                 Cloudflare R2                    transcoder host (cron)
 ──────────────                 (ben@herila.net account)         ──────────────────────
 presign upload ───────────────▶ vora-input      ◀───────────────  reads source (read-only)
 (future)                        (raw uploads)
                                                                  ┌── s3-hls-transcoder
 play HLS  ◀── proxy ──────────▶ vora-encoded     ◀───────────────└── writes HLS output
 (future)                        (by-id/…, mappings/…)               every 15 min (staggered)
```

## Cloudflare R2

Both buckets are in **ben@herila.net's** Cloudflare account (account id
`460f679bfb0a7ee47cc561c1d08e154f`) — deliberately separate from any other project.

| Bucket | Purpose | App access | Transcoder access |
| --- | --- | --- | --- |
| `vora-input` | raw uploaded videos (transcoder source) | read/write | read-only |
| `vora-encoded` | HLS renditions (transcoder output) | read-only | read/write |

- `vora-encoded` has a **CORS** policy allowing `GET`/`HEAD` from any origin, required for
  hls.js to fetch segments via cross-origin XHR. (The presigned, expiring segment URL is the
  access capability; CORS only lets a browser that already holds one read the bytes.)
- Two **least-privilege** scoped R2 API tokens back this:
  - **vora app** — RW on `vora-input`, read on `vora-encoded`.
  - **vora transcoder** — read on `vora-input`, RW on `vora-encoded`.
- The pre-existing `vora` bucket in the same account is unrelated and untouched.

### Transcoder output layout (in `vora-encoded`)

```
by-id/<contentId>/master.m3u8          ← multivariant playlist
by-id/<contentId>/<rung>/index.m3u8    ← media playlist per rung (e.g. 720p/)
by-id/<contentId>/<rung>/init_*.mp4     + seg_*.m4s   ← CMAF fMP4 segments
mappings/<source-key>.json             ← { contentId, hlsRoot, … } lookup by source path
```

`contentId` is `sha256:<hash-of-source-bytes>`, so identical uploads share one output and are
deduplicated automatically.

## App configuration (`.env`)

These keys are already set in the host `.env` (gitignored). Names are listed here for
reference; values are never committed.

```
# Upload / source bucket (app RW)
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_BUCKET=vora-input
AWS_ENDPOINT=https://460f679bfb0a7ee47cc561c1d08e154f.r2.cloudflarestorage.com
AWS_DEFAULT_REGION=auto
AWS_USE_PATH_STYLE_ENDPOINT=true

# HLS output bucket (app read-only)
HLS_BUCKET=vora-encoded
HLS_ENDPOINT=https://460f679bfb0a7ee47cc561c1d08e154f.r2.cloudflarestorage.com
HLS_ACCESS_KEY_ID=…
HLS_SECRET_ACCESS_KEY=…
HLS_DEFAULT_REGION=auto
HLS_USE_PATH_STYLE_ENDPOINT=true
```

`FILESYSTEM_DISK` stays `local` until the media feature ships and wires up an `s3`/`hls` disk.

## Transcoder deployment

The transcoder is [s3-hls-transcoder](https://github.com/bherila/s3-hls-transcoder) — a small
Node project that scans the source bucket on a cron schedule, transcodes with ffmpeg, and
writes HLS to the destination bucket. It is content-addressed, deduplicating, and uses a
destination-bucket lock so overlapping runs are safe.

It runs on the shared Ubuntu host under a dedicated, login-locked `vora` user
(`/home/vora/s3-hls`), with read-only/RW credentials scoped exactly as above in
`local/.env` (chmod 600). The cron schedule is **staggered** (`7,22,37,52 * * * *`) off the
other transcoder on that host and wrapped in `nice -n 19 ionice -c3` so video encoding stays
out of the way of co-located workloads. Logs rotate weekly under `/home/vora/logs/`.

A prebuilt, build-free bundle is published at
[s3-hls-transcoder releases](https://github.com/bherila/s3-hls-transcoder/releases) for
reproducible redeploys.

## What's left to build (app feature)

1. **Upload**: presign `PUT`s to `vora-input` (an `s3` disk on the `AWS_*` config) and record
   uploaded videos.
2. **Readiness**: lazily resolve `mappings/<file_path>.json` from `vora-encoded` to learn when
   a video's HLS is ready (cache the result on the row).
3. **Playback**: an authenticated proxy that serves rewritten `.m3u8` manifests inline and
   302-redirects segments to short-lived presigned `vora-encoded` URLs, plus an hls.js player
   with fallback to the original while transcoding.

This mirrors the vetvet implementation (bherila/vetvet#1365) — that PR is the reference design
to port.
