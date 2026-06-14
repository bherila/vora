# Media: overview

Users upload photos and videos that are stored in object storage (Cloudflare R2)
and surfaced back through signed URLs. Every upload passes through a silent admin
review before anyone other than the uploader can see it.

## Lifecycle

1. **Request** — the client calls `POST /api/media` with the file's type, name,
   MIME type, size, title, visibility, and interest tags. The server validates
   per-type MIME/size rules, creates a `pending` `media` row, and returns a
   **presigned PUT URL**.
2. **Upload** — the browser PUTs the file directly to R2 using that URL (so the
   request never passes through the app and isn't bound by PHP upload limits).
3. **Complete** — the client calls `POST /api/media/{id}/complete`; the server
   verifies the object exists, records its real size, and marks it `ready`. The
   item now enters the review queue with `moderation_status = pending`.
4. **Review** — an admin approves or rejects it (see
   [moderation-and-privacy](moderation-and-privacy.md)). The uploader is never
   shown this state.
5. **Serve** — photos are shown via a signed inline URL; videos play via the
   transcoded HLS stream (see [s3-hls-integration](s3-hls-integration.md)).
6. **Delete** — the owner may delete an item; the source object is removed from
   its bucket and the row is deleted.

## Key code

| Concern | Location |
| --- | --- |
| Routes | `routes/web.php` (`/api/media`, `/api/admin/media`, pages) |
| User controller | `app/Http/Controllers/MediaController.php` |
| Admin controller | `app/Http/Controllers/Admin/AdminMediaController.php` |
| Upload / completion | `app/Services/Media/MediaUploadService.php` |
| HLS resolution | `app/Services/Media/HlsMappingService.php` |
| Delete | `app/Services/Media/MediaService.php` |
| Storage wrapper | `app/Services/FileStorageService.php` |
| Serialization | `app/Support/MediaPresenter.php` |
| Model | `app/Models/Media.php` |
| Enums / traits | `app/Enums/*`, `app/Traits/HasVisibility.php`, `app/Traits/Moderatable.php` |
| Config | `config/media.php`, `config/filesystems.php` |
| Frontend | `resources/js/user/media.tsx`, `resources/js/admin/media.tsx`, `resources/js/media/*` |

## Tuning

Upload size caps, accepted MIME types, signed-URL lifetimes, the object-key
prefix, and the HLS playback base URL are all configured in `config/media.php`
(overridable via the `MEDIA_*` env vars documented there).
