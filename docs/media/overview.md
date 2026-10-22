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

## Account storage usage

Settings → Data & account shows the account's original-file usage. The same
summary is available through authenticated `GET /api/account/storage` for the
signed-in account only. It includes completed uploads (persona galleries and
profile pictures included) and durable Library saves, across all audiences and
moderation states. Existing uploads are included without a backfill.

`active_bytes` and `active_file_count` describe active uploads and saved originals.
`uploaded_bytes` and `saved_bytes` distinguish the two, counting each media row
once per account even when it appears in several collections. Viewing a shared
item alone does not add recipient usage. An uploader's own Library reference
keeps that file active even after its gallery publication is deleted;
`retained_bytes` and `retained_file_count` describe soft-deleted uploads retained
for admin recovery. `total_bytes` includes both. Permanent deletion removes the
file from usage. Completed files without a recorded size are counted in
`unknown_size_file_count` and excluded from byte totals.

This measures logical original-file usage, not the object-storage bill:
thumbnails, reviewed copies, HLS derivatives, and unfinished uploads are excluded.
There is no quota enforcement or billing yet. Storage creation is controlled by
the admin-managed `users.library_enabled` flag, which is off by default and
cannot be set through registration or ordinary account settings.

## Private Library and durable Save

`/library` provides Uploaded, Saved, Notes, and Shared with me views, search,
collections, uploads, note editing, explicit sharing, and protected photo/HLS
playback. The account menu links to Library. Admins enable new uploads, saves,
notes, and collection creation from Admin Users. Disabling storage preserves
existing viewing, removal, and access-revocation operations. Recipients need
no storage entitlement to view an explicitly shared item or collection.

The Library supports selection of owned items on the current page. Bulk actions
add items to a collection, move only the current collection membership, remove
collection membership, or remove items from the Library after confirmation.
Other collection memberships survive a move. Selection resets on search, page,
and section changes. `POST /api/library/items/bulk` accepts at most 100 opaque
item identifiers and validates every item, collection, and source membership
before changing anything. Removing membership or items remains available when
storage is disabled. Adding saved items to a shared collection keeps them private.

The migration makes `media.user_id` nullable so the stored media row can outlive
its uploader. `library_items` are independent account-owned references to that
row, or private text notes. `library_collection_items` only organizes those
references; deleting a collection does not delete its items. Existing favorites
remain bookmarks; the separate **Save to Library** action guarantees retention.

- A private collection belongs to its creator. An optional related-persona
  reference categorizes notes, photos, and videos received outside Vora; it is
  separate from the existing authoring `character_id`. Relating a persona grants
  no access, sends no notification, and never reveals a Separate persona's owner.
  Persona search lists your own and discoverable public personas; a known direct
  persona link can select an unlisted persona when you have viewing access.
  Links use opaque persona identifiers, not enumerable internal account IDs.
  Removing the target persona or its account does not remove the collection or
  its files, or revoke its creator's access.
- The collection owner explicitly chooses recipients. Private notes remain
  private unless deliberately shared. Collection sharing includes current and
  future uploads and original notes. Items saved from someone else stay private
  to the saver, even when organized into a shared collection; they cannot be
  reshared directly or used to grant access beyond the source audience.
  Anyone with ordinary viewing access and Library
  storage enabled can choose
  durable Save; there is no separate uploader-controlled save permission.
  Revoking viewing access stops future saves but does not remove existing saves.
- Durable Save creates a private account-owned reference to an existing stored
  file, after checking current access. It does not copy the object. The saved
  reference retains access after the original uploader deletes their media or
  account, or revokes ordinary viewing access. This persistence must be clear
  in the sharing and saving dialogs; it does not grant access to the original discussion,
  private metadata, or other files. Moderation takedowns still apply to the file.
  Admin removal from deleted content revokes saved playback while preserving
  retained originals until the final Library reference is released.
- Stored files have a lifetime independent of uploader accounts and persona
  records. `UserAccountService` removes that account's Library references before
  purging its original uploads. `MediaService` retains media with other Library
  references, clears uploader/persona/publication links, and soft-deletes the
  publication. The final removal purges the physical file and derivatives only
  when no reference or retention hold remains. Saves and deletion lock the same
  media row in a database transaction, including stale-upload cleanup.
- Saved notes retain a private text snapshot. Saved media uses one existing
  stored file, never another object-store copy. Saved presentations and asset
  routes omit uploader identifiers, original filenames, source keys, private
  collection memberships, and the original discussion. Administrative review
  access alone does not grant the right to create a durable save.

Library-only uploads use `purpose=library`, an empty Specific people audience,
no discoverability, and no feed announcement. They reuse the existing direct
upload, resumable multipart, completion, and moderation pipeline, and stay out
of ordinary profile galleries. A shared Library upload is unavailable to other
users until approved. The uploader can still view their own upload immediately.

The authenticated API lives under `/api/library`: collection and item operations,
`/uploads`, `/notes`, `/save`, and `/personas`. Membership changes only accept the
current account's items and collections. Asset and HLS routes authorize through
the viewer's own reference or an explicit share on every request, independently
of the original publication's audience. Moderation takedowns still deny saved
playback. Saved items authorize only their saver; direct and collection grants
cannot make them viewable or saveable by another account. No public bearer link
or new identity profile is created by sharing.
