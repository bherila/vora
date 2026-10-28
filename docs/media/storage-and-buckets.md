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
| HLS output | `hls` | the transcoder | the app (read; deletes only PDQ image mappings) | `HLS_*` |

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

When an admin approves media, the app copies the reviewed source object to:

```
<MEDIA_KEY_PREFIX>/reviewed/<user-id>/<ulid>.<ext>
```

`MEDIA_REVIEWED_KEY_PREFIX` optionally overrides this directory for **approved
videos only**. For an existing bucket whose transcoder scans `videos/`, set
`MEDIA_KEY_PREFIX=uploads` and `MEDIA_REVIEWED_KEY_PREFIX=videos/reviewed`. This
keeps unfinished uploads and photos outside the scan prefix while allowing the
existing source/HLS bucket pair to handle approved new videos. Without an
override, reviewed video paths retain the default shown above. Existing media
keys are stored on their rows and are not moved by changing these settings.

Client-generated thumbnails/posters are copied to:

```
<MEDIA_KEY_PREFIX>/reviewed-thumbnails/<user-id>/<ulid>.jpg
```

Approved playback and public/discovery URLs use these reviewed copies. The
original upload keys remain client-overwritable until their presigned URLs
expire, so they are never the trust anchor after review.

## Selected saves and downloads

Event galleries and Library shared items support saving a current-page selection
privately. Up to 100 selected references are checked and saved in one transaction;
quota or access failure rolls back the entire operation. Saved files are not
copied and retain the existing independent-reference retention rules.

ZIP downloads accept up to 50 selected files and 2 GiB of actual object bytes.
Larger videos remain available through individual Download actions, including
retained Library files. Preparing an archive checks every selected item's normal
access and original-object read permission, then returns a session/account-bound,
single-use ticket lasting five minutes. Downloading rechecks the entire selection
before response output and each entry before reading it. A later revocation or
storage failure aborts the ZIP instead of skipping files or appending an error
page. Files already delivered while authorized cannot be recalled.

The browser's download manager receives the archive directly; JavaScript never
buffers it as a Blob. Server reads and output chunks are capped at 64 KiB, with
S3 `stream_reads=true` ensuring the SDK does not buffer whole originals first.
ZipStream uses STORE, single-pass headers and ZIP64; PHP still reserves its fixed
16 MiB internal read buffer. A 50 MiB synthetic-file regression checks less than
24 MiB of additional peak memory and bounded reads/output. Archive metadata is
bounded by the 50-entry limit, and only one source stream is held open at a time.

ZIP preparation is throttled to five requests per minute. A per-account cache
lock rejects parallel ZIP streams, with a one-hour lease; streaming checks a
30-minute deadline between entries/output chunks and sets a 30-second network
read timeout where supported. Blocking HTTP backpressure remains subject to the
web server's own timeouts. The lock is released on completion/interruption and
at request termination. Source filenames stay private: other users' files use
opaque media ULIDs with accurate MIME extensions, while an uploader's own ZIP
entries retain sanitized camera filenames in distinct per-media directories.

## Signed URLs

Owner/admin views use signed storage URLs where allowed. Visitor-facing images
and private Library media stream through authorized application routes so object
keys and uploader identifiers stay private. `FileStorageService` issues:

- **Presigned PUT URLs** for direct browser uploads (`getSignedUploadUrl`).
- **Presigned multipart part URLs** for large resumable video uploads.
- **Signed inline view URLs** for displaying photos, uploader/admin source
  video access, and HLS segments (`getSignedViewUrl`).
- **Signed download URLs** for admin original photo/video review/download
  (`getSignedDownloadUrl`). Video playback uses the HLS proxy; explicit Event
  and Library downloads separately enforce their original-file access rules.

Their lifetimes are `MEDIA_UPLOAD_URL_TTL` and `MEDIA_VIEW_URL_TTL` minutes.

Normal uploads use a single presigned PUT. Videos at or above
`MEDIA_MULTIPART_THRESHOLD_BYTES` use S3/R2 multipart upload when
`MEDIA_MULTIPART_UPLOADS_ENABLED=true`: the app creates the multipart session,
presigns each part, stores completed part ETags in the browser for retry/resume,
then completes or aborts the session through the backend.

Bucket CORS for multipart source uploads must allow `PUT` and expose the `ETag`
response header to the browser; without `ETag`, the browser cannot complete the
multipart upload.

## Orphan cleanup

The upload flow creates a `pending` row before the object lands, so abandoned
uploads can leave orphans. The scheduled `media:prune-orphans` command (hourly)
deletes `pending` rows older than `--hours` (default 24), aborts their recorded
multipart sessions, and removes any object they left behind.

R2 should also have an `AbortIncompleteMultipartUpload` lifecycle rule set to
`MEDIA_MULTIPART_INCOMPLETE_RETENTION_DAYS` (default 7 days). That storage-side
rule is the backstop for sessions whose database row was already removed or
whose abort request never reached the app.

## CSP

`app/Csp/CloudflareCspPreset.php` adds the configured R2 endpoint origins to
`connect-src` (uploads, playlist fetches), `img-src`, and `media-src`. The
origins are derived from the disk endpoint config, so no hosts are hard-coded.

## HEIC/HEIF photos

Still HEVC photos (`image/heic` and `image/heif`) retain their exact original
bytes, filename, content hash and logical quota size. The browser prepares a
JPEG display preview, at most 2048 pixels on its longest edge and 5 MiB, before
requesting an upload reservation. If the browser exports JPEG from a camera
file, its JPEG MIME takes precedence over a historical `.HEIC` filename. Empty
or octet-stream MIME metadata can be normalized from `.heic`/`.heif` filenames.
Image sequences and other HEIF codecs are rejected with a per-file explanation.

The JPEG uses the existing thumbnail slot; no extra media row or physical copy
of the original is created by Save. Completion checks the source's bounded
still-image container header and the landed JPEG's bytes/dimensions before
allowing review. These checks do not decode the HEIC on the application server
or prove the uploaded preview corresponds to its original. Admin review labels
the uploader preview and requires **Check original**: the admin browser fetches
the authorized stored original and decodes it independently before enabling
Approve. A failed/unavailable original stays unapprovable in this interface.
HEIC previews are required rather than best-effort: a missing, invalid or failed
preview cannot silently become a ready upload. Other photo/video thumbnails keep
their existing optional behavior. The same preview/reviewed-thumbnail keys follow
existing moderation snapshots, access revocation, durable Saves and final-reference
cleanup. Display URLs use the JPEG; explicit downloads use the preserved original
or its immutable reviewed snapshot, never the JPEG display derivative.

Conversion tries native browser decoding first. A lazy, bundled `heic-to/csp`
fallback runs in a disposable worker; cancellation/timeouts terminate the worker
and conversions are serialized to avoid several full-size pixel buffers. No
private photo is sent to an external conversion service, and no Imagick/libheif
installation is needed on PHP hosts. The CSP permits same-origin/blob workers
without adding JavaScript eval or WebAssembly eval permissions. The unmodified
heic-to dependency's LGPL license is distributed at
`public/licenses/heic-to-LICENSE.txt`; its version/source are pinned in the pnpm
lockfile and [upstream source](https://github.com/hoppergee/heic-to).
