# Media: storage and buckets

Media is stored in Cloudflare R2 (S3-compatible). The app uses three logical
buckets, each mapped to a filesystem disk in `config/filesystems.php`. **Bucket
names and credentials are configured only via environment variables** — never
hard-code them.

## Bucket roles

| Role | Disk | Written by | Read by | Env prefix |
| --- | --- | --- | --- | --- |
| Video source | `s3` | the app (uploads) | the transcoder (read-only) | `AWS_*` |
| Photo store | `photos` | the app (uploads) | the app (signed views) | `PHOTOS_*` |
| HLS output | `hls` | the transcoder | the app (read-only) | `HLS_*` |

Photos live in their own bucket so the video transcoder never wastes work
scanning images it would ignore.

## Environment variables

Each disk takes the standard S3 settings. The `PHOTOS_*` and `HLS_*` values fall
back to the `AWS_*` account/endpoint when omitted, so a single R2 account can
host all three buckets while still letting you scope separate tokens.

```
AWS_ACCESS_KEY_ID=          AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=auto     AWS_BUCKET=
AWS_ENDPOINT=               AWS_USE_PATH_STYLE_ENDPOINT=true

PHOTOS_ACCESS_KEY_ID=       PHOTOS_SECRET_ACCESS_KEY=
PHOTOS_REGION=              PHOTOS_BUCKET=
PHOTOS_ENDPOINT=            PHOTOS_USE_PATH_STYLE_ENDPOINT=

HLS_ACCESS_KEY_ID=          HLS_SECRET_ACCESS_KEY=
HLS_REGION=                 HLS_BUCKET=
HLS_ENDPOINT=               HLS_USE_PATH_STYLE_ENDPOINT=
```

See `.env.example` for the full annotated list. Credentials should be scoped to
the minimum needed: the app's token needs write to the video-source and photo
buckets and read on the HLS bucket; the transcoder's token needs read on the
video source and read/write on the HLS bucket.

## Object keys

Uploads are stored as:

```
<MEDIA_KEY_PREFIX>/<user-id>/<ulid>.<ext>
```

The `ulid` is the media row's public identifier (also used in share links), so
keys are unguessable and don't leak sequential ids. The prefix defaults to
`uploads` (`MEDIA_KEY_PREFIX`).

## Signed URLs

The app never serves object bytes itself. `FileStorageService` issues:

- **Presigned PUT URLs** for direct browser uploads (`getSignedUploadUrl`).
- **Signed inline view URLs** for displaying photos and source video
  (`getSignedViewUrl`).

Their lifetimes are `MEDIA_UPLOAD_URL_TTL` and `MEDIA_VIEW_URL_TTL` minutes.

## Uploads: single PUT vs multipart

Files smaller than `MEDIA_MULTIPART_THRESHOLD` (default 100 MiB) upload with a
single presigned PUT. Larger files use a **presigned multipart upload**: the
server initiates it (`createMultipartUpload`), the browser uploads chunks of
`MEDIA_MULTIPART_PART_SIZE` (default 16 MiB) each to a presigned part URL with
per-part retry, then the server finalises (`completeMultipartUpload`) or aborts.

Two R2 prerequisites for multipart in the browser:

- **CORS** on the upload bucket must allow `PUT` from the app origin **and expose
  the `ETag` response header** (`ExposeHeaders: ["ETag"]`) — the client needs each
  part's ETag to complete the upload.
- A short **lifecycle rule** to abort incomplete multipart uploads (e.g. after
  1 day) is recommended so abandoned parts don't accrue storage cost. This is the
  backstop for the object side of orphan cleanup (see below).

## Orphan cleanup

The upload flow creates a `pending` row before the object lands, so abandoned
uploads can leave orphans. The scheduled `media:prune-orphans` command (hourly)
deletes `pending` rows older than `--hours` (default 24) and removes any object
they left behind. Truly orphaned objects with no row are handled by the R2
lifecycle rule above.

## CSP

`app/Csp/CloudflareCspPolicy.php` adds the configured R2 endpoint origins to
`connect-src` (uploads, playlist fetches), `img-src`, and `media-src`. The
origins are derived from the disk endpoint config, so no hosts are hard-coded.
