# Media: moderation and privacy

Two orthogonal concepts govern who can see a media item: **moderation** (an
internal admin review) and **visibility** (an owner-chosen privacy level). Both
are built as reusable enums + traits so future user-owned content (e.g. Stories)
can adopt them without duplication.

## Moderation (silent admin review)

Every upload starts at `moderation_status = pending`. An admin reviews it for
legality and TOS/AUP compliance and approves or rejects it.

- Enum: `App\Enums\ModerationStatus` (`pending`, `approved`, `rejected`).
- Trait: `App\Traits\Moderatable` — `approve()` / `reject()` (record reviewer +
  timestamp + notes), plus `pendingReview` / `moderationStatus` scopes.
- Admin API: `GET /api/admin/media` (all items, any owner/visibility/status) and
  `POST /api/admin/media/{id}/moderate`.

**The review is silent.** The uploader must never learn the moderation status.
This is enforced by serialization: `MediaPresenter::ownerView()` omits all
moderation fields and the uploader; only `MediaPresenter::adminView()` (used
exclusively by admin routes) includes them. A unit test asserts the owner view
never contains those keys.

## Visibility (owner privacy)

The owner chooses who may see an item once it has been approved.

- Enum: `App\Enums\Visibility`
  - `users` — any signed-in user may discover and view it.
  - `unlisted` — hidden from discovery; reachable only via the direct link
    (`/m/{ulid}`), i.e. anyone who has the link.
- Trait: `App\Traits\HasVisibility`
  - `scopeVisibleTo(?User)` — for listings/discovery (excludes unlisted).
  - `isVisibleTo(?User)` — for direct lookups (allows unlisted via link).

## How they combine

`MediaPolicy` ties them together:

- The **owner** (and admins, via `before()`) can always view their own media,
  regardless of review state — so users can see their own uploads immediately.
- **Other users** can only view an item once it is **approved** *and* its
  visibility permits it. A pending or rejected item is never exposed to anyone
  but the owner and admins.

## Reusing for new content types

To make another model moderated and/or privacy-aware:

1. Add the matching columns (`moderation_status`, `moderated_by_user_id`,
   `moderated_at`, `moderation_notes`, and/or `visibility`, `user_id`).
2. `use` the `Moderatable` and/or `HasVisibility` traits and cast the enums.
3. Serialize through a presenter that hides moderation from owners, mirroring
   `MediaPresenter`.
