# Posts, discussions, and Interest contexts

> **Status.** The Post-as-discussion-root model, one-level replies, and privacy
> clamping are built. Interest contexts, canonical media/story discussions,
> tombstones, *Your activity*, and comment polling land with #193 — sections
> below mark which. The contracts themselves are settled either way.

## Post is the single discussion root

There is one discussion model. `Post` is it. There are no separate
media-comment, story-comment, or Interest-comment models, and adding one would
fragment moderation, privacy, and deletion into parallel implementations that
drift.

Every discussion in the product is comments on a Post:

| Conversation | Representation |
| --- | --- |
| Standalone update | Post with no context and no attachments |
| Interest post | Post with a primary Interest context (#193) |
| Media/story discussion | The content's **canonical** Post (#193) |
| Direct message | The chat system — separate, see `ChatConversation` |

Chat and posts are genuinely different systems. Do not merge them.

## Comments

- Plain text. No inline media — a user who wants to share media creates a post
  and links it. Inline media in comments would drag in upload, moderation,
  privacy clamping, preview, and deletion complexity for little gain.
- **One level of threading.** A reply's parent is always a top-level comment.
- Comments have **no independent audience**. They inherit the parent Post's
  current effective visibility, re-evaluated on every request. A relationship or
  audience change revokes access immediately.
- `PostComment::scopeThreadVisibleTo` is the visibility source of truth: a
  comment the viewer may see whose parent the viewer may also see. It exists so a
  moderated-away parent also hides its orphaned replies.
- Comment counts must use the same scope as the listing — see
  `Post::scopeWithEngagementCounts`.

Deletion and tombstone semantics are in
[conventions/moderation.md](../conventions/moderation.md#removal-paths-are-distinct-and-must-stay-distinct).

## Attachments are content payload only

`post_attachments` carries the content a post displays: a Character profile card,
Media, or a Story. Every attachment type is ownership-checked, privacy-clamped,
and identity-boundary-checked — uniformly, with no per-type exceptions.

Two things that are *not* attachments and must not be modelled as such:

- **The byline.** `posts.character_id` is the persona a post is published *as*
  ("Post as" in the composer). Attaching a character means *sharing that
  character's profile card*. Different relationships, both valid, never merged.
- **Topic.** An Interest is not content payload — it has no owner, no privacy
  policy, and no identity. It is the post's *context*, carried by
  `posts.context_interest_id` (#193), not by an attachment row.

A post may carry several media attachments ordered by `position`; the clamp
already intersects N policies correctly, so galleries need no new model.

## Interest as context (#193)

An Interest is a **place a post is made in**, not a decorative tag.

- **At most one primary context per post**, held in a single nullable FK column.
  The cardinality invariant is enforced by construction — see
  [schema-portability.md](../conventions/schema-portability.md#do-not-use-partial-or-filtered-unique-indexes)
  for why this is a column and not a pivot with an `is_primary` flag.
- Interests are addressed by an **immutable slug**, generated at creation and
  never regenerated on rename, so links survive.
- Filtering is **exact-Interest only**. A post in a child Interest does not
  appear under its parent.
- The Interest page and the main feed share one query builder. They must not
  become two visibility paths.
- **Interest context never grants access** — see
  [privacy-and-visibility.md](../conventions/privacy-and-visibility.md#context-never-grants-access).

## Canonical media/story discussions (#193)

Each media or story item has **at most one** canonical discussion Post, held as
`canonical_post_id` on the content with a plain nullable unique index. The feed
announcement card and the content detail page resolve to the same thread.

- First claim wins. `AnnouncementPostService` creates it on approval when
  announcements are on; a manual share claims it first if there is one.
- Content whose owner declined the announcement still gets a discussion, created
  lazily on first comment and flagged `is_feed_hidden` so it never produces a
  feed card.
- A second post attaching the same content is a valid post — it just is not
  canonical. This deliberately leaves room for reshare without letting a reshare
  fragment the discussion.
- Attached-content privacy continues to clamp the discussion's visibility.

## Author-scoped control (#193)

Losing access to a discussion must not strand what you wrote there. *Your
activity* lists a user's own posts, comments, and replies **queried by authorship
alone**, never joined against parent visibility, with author-scoped deletion
routes that do not require a readable parent.

Authorization there proves ownership of the contribution itself — bypassing
parent visibility must never bypass ownership. Responses are neutral: when the
parent is unavailable, no parent, Interest, media, persona, or relationship
metadata appears. See
[privacy-and-visibility.md](../conventions/privacy-and-visibility.md#neutral-failure).

Named *Your activity*, not "Activity Log" — the latter is the admin audit log.

## See also

- [Privacy and visibility](../conventions/privacy-and-visibility.md)
- [Moderation and account states](../conventions/moderation.md)
- [Polling and freshness](../frontend/polling-and-freshness.md)
