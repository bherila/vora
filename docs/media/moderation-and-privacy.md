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

- Enum: `App\Enums\Audience`: Everyone, Followers, Mutuals, or Specific people.
  An empty Specific people allowlist is owner-only.
- Trait: `App\Traits\HasPrivacyPolicy`: audience checks apply to both listings
  and direct links. Discoverability is separate; a link never bypasses access.
- Persona-authored media inherits that persona's audience. A Library
  collection's related-persona link is organizational only and grants no access.

Library sharing uses explicit item/collection recipients. All current and future
items in a shared collection are included; notes stay private unless explicitly
shared or placed in such a collection. Viewing permits durable Save for accounts
with Library storage enabled. Revocation prevents further access through the
share, but does not remove existing independent saves. Saved media is still
subject to moderation, and has no access to the source discussion or metadata.

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
   `moderated_at`, `moderation_notes`, and/or `audience`, `discoverable`, `user_id`).
2. `use` the `Moderatable` and/or `HasPrivacyPolicy` traits and cast the enums.
3. Serialize through a presenter that hides moderation from owners, mirroring
   `MediaPresenter`.
