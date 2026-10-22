# Private Library rollout checks

The automated Library tests use fake object storage. They exercise application
permissions and reference lifetimes, but do not prove that real uploads, the
external transcoder, presigned segment requests, or a deployed PHP server work.
Use this procedure before enabling Library for a wider group.

## Isolated resources

Use a staging application with its own database and dedicated photo, video-source,
and HLS-output buckets. Configure the external transcoder to read that staging
video-source bucket and write that staging HLS-output bucket. Apply the normal
deployment process; this procedure does not run migrations or modify database
configuration. Do not point developer tests at staging or production databases.

Create approved, non-admin uploader, recipient, and outsider staging accounts
specifically for this run, plus a separate admin session. Give the uploader and
recipient Library access. Use a unique run
label such as `library-smoke-YYYYMMDD-<random>` in their display names, collection
names, and uploaded titles. Upload only generated, nonsensitive test content.
Use a short video with unique pixels/audio so content-addressed HLS output cannot
be shared with an earlier run. Record the exact test account IDs and media/item
ULIDs privately; do not put signed URLs, storage credentials, or other users'
identifiers in test reports.

The diagnostic below only reads the four explicitly selected resources. It does
not create accounts, upload content, approve media, trigger the transcoder,
delete accounts, or clean up storage. It can also read explicitly selected test
fixtures on an existing deployment, but a dedicated staging environment is the
preferred place for destructive lifecycle checks.

## Upload and playback

1. In the uploader's browser profile, create a private Library collection and
   upload a real photo and short video. Confirm both complete. Confirm neither
   appears in their gallery or public activity. Capture the two media ULIDs from
   the upload API responses in browser developer tools.
2. Through the normal admin media review UI, approve both test files. Wait for
   the external transcoder's next run (normally every 15 minutes). Missing HLS
   mappings must show processing rather than fall back to the original video.
3. Explicitly share the collection with the second test account. In that separate
   browser profile, render the photo and play and seek the video with audible
   audio where present. Check the browser network panel: manifests use the
   authenticated Library proxy, segments redirect successfully to short-lived
   object-store URLs, and no CORS or HLS errors appear. Repeat on a mobile browser
   and record the browser/device and tested commit.
4. As the recipient, save each item to their own Library. Capture those two new
   Library item ULIDs from their item links or save API responses. Revoke the
   uploader's collection share. Confirm the original shared item links now fail,
   while the recipient's independently saved photo and video still work. A third
   unshared account must not be able to open either saved item.

On the staging application host, run the following against those exact fixtures.
Shell variables below contain only the test ULIDs, never credentials:

```bash
php artisan ops:media-integration-check --read-storage --json \
  --photo="$rollout_photo_ulid" --video="$rollout_video_ulid" \
  --saved-photo="$rollout_saved_photo_ulid" --saved-video="$rollout_saved_video_ulid"
```

The command exits zero only when all checks pass. It checks completed/approved
rows, independent saved references owned by an active approved account, original
and immutable reviewed object sizes and readable bytes, the real HLS mapping,
bounded master/rendition manifest traversal, referenced init/segment bytes, and
segment signing. Reads are bounded to 64 KiB per manifest/mapping, 128 HLS
objects, and the first 64 bytes of each segment/init object. Use a short test
video so it fits those limits. Output contains only named check booleans, with no
account IDs, object keys, endpoints, or signed URLs. Storage exception details
are deliberately omitted. It performs no database or storage writes and does
not refresh cached HLS state.

Passing this command is evidence that selected real objects and HLS artifacts
are present and readable. Browser playback is still required to validate encoded
content, browser CORS, session authorization, and redirected segment downloads.

## Retention and cleanup

1. Verify the original uploader is the disposable account created for this run.
   Permanently remove **only that test account** using Admin Users. Do not use a
   real user's account and do not automate broad account selection. This action
   is irreversible. The recipient's saved references must remain.
2. Reload the saved photo and play/seek the saved video in the recipient's
   browser. Run the same diagnostic with `--expect-uploader-removed` added. That
   additionally requires both source rows to be detached from their uploader and
   retained as deleted publications. Both originals and reviewed objects must
   remain until their final reference is released.
3. Remove both saved items from the recipient's Library. Verify with read-only
   storage inspection that **only the exact original/reviewed keys recorded for
   this run** and their private mappings are gone. Verify the test-only HLS tree
   is removed when unreferenced. Never delete a broad prefix or a `by-id` tree
   merely because this run used it: content hashes can be shared by other media.
   If an object remains, investigate cleanup permissions/retention rather than
   manually sweeping a bucket. The diagnostic intentionally fails once its
   selected references and media rows have been removed.
4. Remove the remaining disposable recipient/outsider accounts and run-specific collection
   through normal application controls. Record results for upload, approval,
   transcoding, desktop/mobile playback, revocation, uploader purge, final-reference
   cleanup, and account storage totals. Keep integration failures separate from
   passing fake-storage automated tests.

## Required permissions

The application needs read/write/delete access to source/photo objects and
read/delete access to HLS mappings/output for its permanent-deletion path.
`HlsService::deleteIfUnreferenced()` removes private mappings and removes a
content-addressed HLS tree only after checking all media rows, including retained
deleted rows, for a reference. A read-only HLS token permits playback but cannot
complete that application cleanup. Verify the deployed token's permissions and
the transcoder's reference cleanup configuration before claiming final-reference
purging works. The diagnostic never attempts deletion and cannot prove delete
permissions; the disposable lifecycle above provides that evidence.
