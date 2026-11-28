# Media: video chapters, moments, and scene suggestions

> **Status: design, not implemented.** This document records the agreed design
> for annotating long videos. Nothing described here exists yet; sections are
> written in the present tense to read as the target contract. Update it as
> phases land, and move settled behaviour into the other media docs.

Long videos — digitized camcorder tapes are the motivating case, often an hour
or more each — are hard to browse with a bare player. This design adds:

1. **Chapters**: named, ordered start times that viewers can jump between.
2. **Moments**: snapshots of a single frame, with an optional caption, that any
   viewer can save and (by default) share with everyone else who can watch the
   video.
3. **Scene suggestions**: cut points detected by the transcoder, with small
   thumbnails, that the chapter editor offers as candidate chapter starts.

The transcoder side of the contract (scene analysis, frame extraction, object
layout) is specified in the transcoder's
[integration guide, §7](https://github.com/bherila/s3-hls-transcoder/blob/main/docs/INTEGRATION.md).

## Starting point

- `resources/js/media/HlsVideoPlayer.tsx` renders a bare `<video controls>` and
  keeps the hls.js instance private, so nothing else on the page knows the
  playback position. There are no deep links, keyboard shortcuts, or frame
  stepping.
- `resources/js/media/view.tsx` is the only full video page. Videos also play in
  Library and Event surfaces through `MediaPlayer`.
- The poster is a JPEG captured in the browser at 0.5 s during upload and
  reviewed with the video. For digitized tapes that frame is usually blue
  screen, black, or noise.
- The transcoder emits CMAF fMP4 HLS (360p–1080p, capped at the source), 6 s
  segments, and a fixed 48-frame GOP. Stepping one frame backward decodes at
  most 48 frames.
- Browser frame capture works today on the hls.js path: segments are appended
  through Media Source Extensions, so drawing the `<video>` onto a canvas does
  not taint it. The native-HLS fallback (older Safari) cannot capture.
- vora stores no video duration or frame rate yet.

## Player

### Controller

`HlsVideoPlayer` exposes a controller (context or callback) with:

- `currentTime`, `duration`, `seek(seconds)`, `paused`;
- `presentedMediaTime`: the timestamp of the frame actually on screen, from
  `requestVideoFrameCallback`; `currentTime` can differ from it by a fraction of
  a frame;
- `frameRate`: from `metadata.json` (`source.frameRate`, see Backend), falling
  back to hls.js `level.frameRate`, then to the spacing of presented frames
  measured during playback;
- `pinTopQuality()` / `releaseQuality()`: lock hls.js to its highest level while
  capturing, so the frame being tuned is the one the server will extract.

Native controls stay: they give accessibility, fullscreen, and
picture-in-picture for free. New UI lives in a toolbar and strip below the
video. Native fullscreen hides that UI; a wrapper-fullscreen button keeps it on
desktop, and the WebVTT chapter track covers native fullscreen where the browser
shows chapter tracks.

### Navigation

- Deep links: `#t=1h02m30s` (and `#t=3750`) seek on load. "Copy link at this
  time" on the toolbar, and on every chapter and moment.
- Resume: remember the last position per video in `localStorage` (wrapped in
  try/catch; never relied on), offered as "Resume at 41:12".
- Frame stepping while paused: seek to the centre of the target frame
  (`(n + 0.5) / fps`) to avoid boundary rounding, then confirm the presented
  frame through `requestVideoFrameCallback` before updating the readout.
- Timecode readout `HH:MM:SS.ff`.

Keyboard shortcuts, ignored while focus is in a text field:

| Key | Action |
| --- | --- |
| `,` / `.` | Step one frame back / forward (pauses) |
| `<` / `>` | Step one second back / forward |
| `[` / `]` | Previous / next chapter |
| `S` | Save a moment at the current frame |
| `M` | Add a chapter at the playhead (chapter edit mode) |

## Chapters

### Viewing

- A chapter bar under the video, segmented by chapter, with the current chapter
  highlighted. Hovering shows the title; clicking seeks. It is drawn as SVG
  (`x`/`width` attributes), so segment widths need no inline `style`, which the
  CSP rules discourage.
- "Previous / Next chapter" buttons and the current chapter's title.
- A chapter list: in a side rail on wide screens, below the video on phones.
  Each entry shows its start time and a thumbnail (the scene thumbnail nearest
  its start when one exists, otherwise an extracted frame).
- `GET /api/media/{ulid}/chapters.vtt` is attached as `<track kind="chapters">`.

### Editing

Only the video's uploader edits chapters (an authorship relationship, not a
role). Edit mode adds:

- "Add chapter here" at the playhead; rename; delete;
- nudge a start by ±1 s or ±1 frame, or "set to playhead";
- the first chapter is pinned to 0:00;
- **Paste a list**: lines such as `00:00 Christmas '89` and `12:31 Grandma's
  birthday` are parsed into chapters, so family members can transcribe tape
  labels;
- **Scene suggestions**: faint markers on the bar and a list of thumbnails, each
  with Accept and Dismiss, plus "Accept all". See Scene suggestions below.

The editor saves the whole list at once. Chapters live only in the database, so
editing never requires re-transcoding.

## Moments

### Capture

1. The viewer presses the camera button (or `S`). The video pauses and a capture
   tray opens; quality is pinned to the top level.
2. The tray shows the timecode, frame-step buttons (±1 frame, ±1 s), a caption
   field (optional, at most 280 characters), and visibility: **Everyone who can
   see this video** or **Only me**.
3. On Save, the client records `time_ms = floor(presentedMediaTime × 1000)`. Two
   people who pick the same frame produce the same value.
4. The client draws the frame to a canvas and keeps it as a local `blob:`
   preview (the CSP already allows `blob:` images).

### Browsing

- A strip of moment thumbnails under the chapter bar, each with time, caption,
  and the saver's avatar; filter "Everyone / Mine". Moments are also dots on the
  chapter bar.
- Opening a moment shows the full frame, who saved it, Seek, Download, and Copy
  link.
- Uploader actions: **Use as cover** (replaces the poster) and **Turn into a
  chapter start**.
- Moments are attributed to the authoring identity in use, following the
  existing persona byline rules; a Separate persona never reveals its owner.

### Frame images: provisional and authoritative

Every moment ends up with an **authoritative frame** extracted by the
transcoder's frame worker from the top HLS rendition. To avoid waiting for it,
the saver also uploads a **provisional** JPEG from their canvas.

The safety rule: **a provisional image is only ever served to the account that
uploaded it, and is never promoted.** Other viewers only ever receive frames cut
by the server from approved output, so moments add no new moderation surface.
Captions are text and use the normal report flow.

| Viewer | Authoritative frame ready | Not ready yet |
| --- | --- | --- |
| The moment's author | authoritative | provisional |
| Everyone else | authoritative | placeholder ("frame being prepared") |

One presenter method makes this choice; no other code path builds image URLs
for moments. The moment itself — time, caption, author, marker — is visible to
its audience immediately; only the image waits.

Lifecycle of a new moment:

1. `POST /api/media/{ulid}/moments` records `time_ms`, the content ID being
   played, caption, and visibility.
2. If the authoritative frame for (content ID, `time_ms`) already exists, the
   moment is ready and no upload happens.
3. Otherwise the response includes a presigned PUT for the provisional image on
   the thumbnail disk. The client uploads it and calls `…/provisional/complete`,
   which checks that it is a JPEG or WebP image within the size and dimension
   limits (GD).
4. vora writes a frame request into the source bucket and wakes the frame
   worker.
5. A scheduled command (every minute) checks pending moments for the frame
   through the content directory derived from `hlsRoot`. When the frame exists,
   it records `frame_status = ready`, deletes the provisional object, and
   deletes the request object. When the worker reports an error, the moment
   becomes `failed`: other viewers keep the placeholder, the author keeps the
   provisional, and the author can retry.
6. Optionally, compare a perceptual hash of the provisional with the
   authoritative frame and log large differences. This catches timeline
   misalignment between player and worker; it is not a security control.

A failure or timeout never shows the provisional to anyone else.

### Use as cover

When the uploader chooses a moment with a ready authoritative frame on an
approved video, vora copies that frame into a new row-private poster object and
points the poster (including the reviewed poster) at it, then deletes the
previous poster objects. No re-review is needed: the bytes come from approved
output, not from the browser.

## Scene suggestions

The transcoder writes `scenes.json` and a small JPEG for each strong cut into
the content directory, as specified in its integration guide. vora:

- reads `scenes.json` through the directory of `hlsRoot` (never by assembling a
  `by-id/` key) and caches it per content ID;
- serves `GET /api/media/{ulid}/scene-suggestions` to the uploader only;
- filters raw cuts to the strongest ones with a minimum gap (about 20 s by
  default, adjustable in the editor), and places a suggestion at the **end** of
  a black segment rather than at the cut into it;
- serves cut thumbnails through a dedicated, policy-gated route.

Videos transcoded before scene analysis existed get suggestions once the
transcoder's flagged backfill reaches them. Until then the editor simply shows
none.

## Backend

### Data model

`video_chapters`

| Column | Notes |
| --- | --- |
| `id` | |
| `media_id` | FK, cascade on delete |
| `start_ms` | unsigned int; unique with `media_id` |
| `title` | string, at most 120 characters |
| `user_id`, `character_id` | author; nullable, cleared when the chapters are anonymized |
| timestamps | |

`video_moments`

| Column | Notes |
| --- | --- |
| `id`, `ulid` | ULID in URLs |
| `media_id` | FK, cascade on delete |
| `user_id`, `character_id` | author and authoring identity |
| `content_id` | HLS content ID played at capture |
| `time_ms` | unsigned int; index with `media_id` |
| `caption` | nullable, at most 280 characters |
| `visibility` | `shared` or `private` |
| `frame_status` | `pending`, `ready`, `failed` |
| `provisional_key`, `provisional_status`, `provisional_size_bytes` | row-private object on the thumbnail disk |
| timestamps | |

`media` gains `video_duration_ms` and `video_frame_rate`, cached from
`metadata.json` by `HlsService::ensureResolved()`.

Moments are hard-deleted; reversible states hide them through the visibility
scope instead (see Cleanup).

### API

All routes are JSON in the web group, use Form Requests, and authorize through
policies. Every write has a named limiter in `App\Support\RateLimits`.

| Route | Who | Notes |
| --- | --- | --- |
| `GET /api/media/{ulid}/chapters` | viewers | |
| `GET /api/media/{ulid}/chapters.vtt` | viewers | WebVTT chapters track |
| `PUT /api/media/{ulid}/chapters` | uploader | replaces the list; `chapters` `max:200`; `start_ms` within the duration; limiter `video-chapters` |
| `GET /api/media/{ulid}/scene-suggestions` | uploader | filtered suggestions with thumbnail URLs |
| `GET /api/media/{ulid}/scene-suggestions/{ms}/thumbnail` | uploader | signed redirect |
| `GET /api/media/{ulid}/moments` | viewers | cursor pagination, at most 50 per page |
| `POST /api/media/{ulid}/moments` | viewers | limiter `video-moments`; caps per user per video (100) and per video (2,000) |
| `POST /api/moments/{ulid}/provisional/complete` | author | |
| `PATCH /api/moments/{ulid}` | author | caption, visibility |
| `DELETE /api/moments/{ulid}` | author or uploader | |
| `GET /api/moments/{ulid}/image/{full\|thumb}` | per the table above | signed redirect; audited like HLS segment redirects |
| `POST /api/media/{ulid}/cover` | uploader | from a ready moment |

Moments become a reportable type (`video_moment`) in `AdminReportController`.

### Authorization and visibility

One scope, `VideoMoment::scopeVisibleTo($viewer)`, applies everywhere:

- the viewer can view the video (`MediaPolicy@view` and the existing
  restricted-viewer check);
- neither the viewer nor the moment's author (account or persona) has blocked
  the other;
- the author is not suspended with content hidden (the existing
  `banned_at` / `ban_hides_content` filter in `User`);
- `visibility = shared`, or the viewer is the author;
- a viewer whose only access is a Library save sees their own moments and the
  chapters, but not other people's moments: a save does not carry the
  publication's discussion. Such viewers may create private moments only.

Chapters follow video visibility. Editing chapters, reading scene suggestions,
and "Use as cover" require being the uploader.

### HLS proxy hardening

`MediaController@streamHls` currently signs any safe relative path inside the
content directory, which already includes `metadata.json`. Once frames and scene
files live there, it would let any viewer fetch them and bypass moment
visibility. Before frames ship, the proxy is restricted to playlist (`.m3u8`)
and media (`.m4s`, init `.mp4`) objects, and frames and scene thumbnails are
served only through the dedicated routes above.

### Storage and quota

- Authoritative frame: about 60–120 KB at 480p, 200–400 KB at 1080p, plus a
  thumbnail. Content-addressed by (content ID, `time_ms`), so duplicates share
  one object.
- Provisional: at most 1.5 MB, deleted once the authoritative frame exists.
- Scene thumbnails: about 8 KB each, roughly 1 MB per hour of tape.

These are derivatives, like posters and HLS output, so they do not count toward
account storage quotas. Per-user and per-video caps bound them instead.

## Cleanup

Captures leave three kinds of data, each with one owner:

| Data | Location | Deleted by |
| --- | --- | --- |
| Authoritative frames, scene files | the video's content directory in the HLS bucket | the transcoder's reference-counted cleanup, which deletes the whole directory with the last mapping; vora never deletes them |
| Provisional images | thumbnail disk, one per moment | vora, explicitly |
| Moment and chapter rows | database | vora |

**Reversible states hide; only permanent removal deletes.** Soft deletes, bans,
audience changes, and blocks are handled by the visibility scope, so admin
restore brings everything back unchanged.

| Event | Moments | Chapters | Provisionals | Frames |
| --- | --- | --- | --- | --- |
| Uploader soft-deletes the video | hidden | hidden | kept | kept |
| Admin restores it | visible | visible | — | — |
| Permanent purge (`MediaService::purgeObjects`) | deleted | deleted | **deleted** | transcoder cleanup |
| Publication released while Library saves remain (`MediaService::delete`, including uploader account deletion) | deleted | **kept, anonymized** | deleted | kept while saves remain |
| Video rejected or removed by an admin | hidden | hidden | **deleted immediately** | reviewed copy deleted, then transcoder cleanup |
| Uploader suspended, content hidden | hidden | hidden | kept | kept |
| Moment author suspended, content hidden | their moments hidden | — | kept | kept |
| Legal hold | untouched | untouched | untouched | untouched |
| Moment author deletes their account | their moments deleted | — | deleted | kept |
| Persona deleted | as persona deletion treats posts and comments | — | same | kept |
| Block, unfollow, audience change | hidden by scope | follows video | — | — |
| Moment deleted (author, uploader, or report) | deleted | — | deleted | left until directory cleanup |

Notes:

- **Rejection must delete provisionals at once.** A provisional is a copy of the
  video's content outside the HLS directory, so the takedown path would never
  reach it otherwise.
- **Anonymized chapters**: when the publication is released but Library saves
  keep the video, chapters keep their titles and times, `user_id` and
  `character_id` are cleared, and no one can edit them. They are deleted with
  the final Library reference, when an admin removes saved access, or by an
  admin individually (titles can contain personal details).
- **Legal hold** needs no special code: cleanup runs only inside the
  permanent-purge paths, which a hold already blocks. A test proves it.
- A frame left after its moment is deleted is unreachable (frames are served
  only through a visible moment) and is removed with the content directory.
- Re-encoding writes new segment names and does not prune old objects, so
  frames survive a re-encode but come from the old encode. If a ready frame is
  ever missing, vora re-requests it.

Implementation:

- One service with `purgeForMedia`, `deleteProvisionalsForMedia`,
  `anonymizeChaptersForMedia`, and `purgeForUser`, called explicitly from
  `MediaService::purgeObjects`, the Library-retained branch of
  `MediaService::delete`, `MediaModerationService::reject`, and
  `UserAccountService::purge` (before the user row is deleted). Not model
  events: force-deleting a user cascades rows in the database without firing
  model hooks, which would orphan storage objects.
- `media:prune-orphans` also removes moments whose provisional upload was never
  completed.
- R2 lifecycle rules backstop the provisional prefix (30 days) and the
  `frame-requests/` prefix (7 days).

## Validation

- Feature tests (SQLite, `Storage::fake`) for every row of the cleanup table,
  asserting rows, objects, and visibility.
- A two-account test that the second account never receives a provisional URL,
  from the moments API or from page bootstrap data.
- Census coverage: named limiters on every new write, `max:` on the chapters
  array, bounded integers for `time_ms` and `start_ms`.
- Frame accuracy: a test clip with a burned-in frame counter and hard cuts at
  known frames. Capturing frame N in the browser (hls.js and native Safari) must
  yield an authoritative frame showing N, and detected cuts must land within one
  frame. The transcoder's integration guide describes the clip.
- Playwright: capture, frame step, save, placeholder for a second account, swap
  to the authoritative frame (with a stubbed worker), chapters editing and
  navigation, on desktop and mobile.

## Phases

| Phase | Scope | Repositories |
| --- | --- | --- |
| 1 | Player controller, deep links, frame stepping, resume, shortcuts | vora |
| 2 | Chapters: viewing, editing, paste import, WebVTT, cached duration and frame rate | vora |
| 3 | Scene analysis with cut thumbnails and flagged backfill; suggestions in the editor | transcoder, then vora |
| 4 | Moments: frame worker, provisional uploads, proxy hardening, cleanup, Use as cover | transcoder and vora |

Deinterlacing interlaced sources (`bwdif`) is a separate transcoder change. It
improves playback and frames of interlaced tapes, and the re-encode it requires
also produces scene data for those videos.

## Open decisions

Defaults assumed above, pending confirmation:

- Chapters are edited by the uploader only; other people contribute moments.
- No notification when someone saves a moment. If one is added later, it needs
  a tighter limiter of its own.
- Moments and chapters start on the full media page; Library and Event viewers
  follow once the page version is settled.
