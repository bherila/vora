# s3-hls video integration (infrastructure)

This document describes the storage + transcoding plumbing that backs vora's
media-upload feature. The R2 buckets, credentials, and the transcoder are set up
and running. For the **application** side of the contract (how the app uploads,
resolves playback, and deletes) see [media/s3-hls-integration.md](media/s3-hls-integration.md).

> **No secrets — and no bucket names or account ids — live in this repo.** R2
> keys and concrete bucket/account identifiers exist only in the (gitignored)
> `.env` on the app host and on the transcoder host. This file references env-var
> **names**, bucket **roles**, and token **scopes** only.

## What it does

vora lets users upload videos. Rather than stream the single uploaded original
(slow to start, fixed quality), an out-of-band transcoder produces an
adaptive-bitrate **HLS** ladder (H.264/AAC, 360/480/720/1080p, capped at source
resolution) that the app plays back through an authenticated proxy.

```
 vora (Laravel)                 Cloudflare R2                 transcoder host (cron)
 ──────────────                 ─────────────                 ──────────────────────
 presign upload ───────────────▶ video-source   ◀───────────── reads source (read-only)
                                 (raw uploads)
                                                              ┌── s3-hls-transcoder
 play HLS  ◀── proxy ──────────▶ hls-output      ◀───────────└── writes HLS output
                                 (by-id/…, mappings/…)            every 15 min (staggered)
```

## Cloudflare R2

The buckets live in a dedicated Cloudflare account, deliberately separate from
any other project. Two logical buckets back the feature (a third holds photos —
see [media/storage-and-buckets.md](media/storage-and-buckets.md)):

| Role (env var) | Purpose | App access | Transcoder access |
| --- | --- | --- | --- |
| video source (`AWS_BUCKET`) | raw uploaded videos (transcoder source) | read/write | read-only |
| HLS output (`HLS_BUCKET`) | HLS renditions (transcoder output) | read-only | read/write |

- The HLS-output bucket has a **CORS** policy allowing `GET`/`HEAD` from any
  origin, required for hls.js to fetch segments via cross-origin XHR. (The
  presigned, expiring segment URL is the access capability; CORS only lets a
  browser that already holds one read the bytes.)
- Two **least-privilege** scoped R2 API tokens back this:
  - **vora app** — RW on the video-source bucket, read on the HLS-output bucket.
  - **vora transcoder** — read on the video-source bucket, RW on the HLS-output bucket.

### Transcoder output layout (in the HLS-output bucket)

```
by-id/<contentId>/master.m3u8          ← multivariant playlist
by-id/<contentId>/<rung>/index.m3u8    ← media playlist per rung (e.g. 720p/)
by-id/<contentId>/<rung>/init_*.mp4     + seg_*.m4s   ← CMAF fMP4 segments
mappings/<source-key>.json             ← { contentId, hlsRoot, … } lookup by source path
```

`contentId` is `sha256:<hash-of-source-bytes>`, so identical uploads share one
output and are deduplicated automatically.

## App configuration (`.env`)

These keys are set in the host `.env` (gitignored). Names are listed here for
reference; values — including bucket names and the R2 account endpoint — are
never committed. See `.env.example` and `config/filesystems.php`.

```
# Upload / source bucket (app RW)
AWS_ACCESS_KEY_ID=…        AWS_SECRET_ACCESS_KEY=…
AWS_BUCKET=…               AWS_ENDPOINT=…
AWS_DEFAULT_REGION=auto    AWS_USE_PATH_STYLE_ENDPOINT=true

# HLS output bucket (app read-only)
HLS_ACCESS_KEY_ID=…        HLS_SECRET_ACCESS_KEY=…
HLS_BUCKET=…               HLS_ENDPOINT=…
HLS_REGION=auto            HLS_USE_PATH_STYLE_ENDPOINT=true

# Separate photo bucket (app RW) — see media/storage-and-buckets.md
PHOTOS_BUCKET=…            PHOTOS_ENDPOINT=…
PHOTOS_ACCESS_KEY_ID=…     PHOTOS_SECRET_ACCESS_KEY=…
```

## Transcoder deployment

The transcoder is [s3-hls-transcoder](https://github.com/bherila/s3-hls-transcoder) —
a small Node project that scans the source bucket on a cron schedule, transcodes
with ffmpeg, and writes HLS to the destination bucket. It is content-addressed,
deduplicating, and uses a destination-bucket lock so overlapping runs are safe.

It runs on a shared Ubuntu host under a dedicated, login-locked service user
(`/home/<user>/s3-hls`), with read-only/RW credentials scoped exactly as above in
`local/.env` (chmod 600). The cron schedule is **staggered** (`7,22,37,52 * * * *`)
off the other transcoder on that host and wrapped in `nice -n 19 ionice -c3` so
video encoding stays out of the way of co-located workloads. Logs rotate weekly
under `/home/<user>/logs/`.

Source deletions are reaped by the transcoder's reference-counted cleanup pass
(`CLEANUP_DELETED_SOURCES=true`), which removes a transcoded output only once no
remaining source maps to its content hash.

A prebuilt, build-free bundle is published at
[s3-hls-transcoder releases](https://github.com/bherila/s3-hls-transcoder/releases)
for reproducible redeploys.
