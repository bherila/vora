# Privacy and visibility

The rules every content surface must follow. These are invariants, not defaults —
a new surface does not get to reinterpret them, and a change here is a change to
the product's safety contract.

## Audience

`App\Enums\Audience` is the authorization gate, shared across all user-owned
content via the `HasPrivacyPolicy` trait.

| Case | Meaning |
| --- | --- |
| `Everyone` | Any signed-in, **approved** user. Not the anonymous public internet. |
| `Followers` | Accounts with an accepted follow of the owner |
| `Mutuals` | A follow in both directions |
| `SpecificPeople` | An explicit per-item allowlist |

`Everyone` never means anonymous. There is no anonymous public read surface for
user content, and adding one would be a product decision, not an implementation
detail.

Audience is deliberately separate from **discoverability**. The audience decides
*who may access*; the `discoverable` flag decides whether an item is listed on
browsing surfaces or reachable only by direct link. **A share link never
escalates access beyond the audience tier.**

## Clamping

Content that composes other content takes the intersection of every policy
involved, never the union. `PostService::clampPrivacy` is the reference
implementation: a post attaching a followers-only media cannot be `Everyone`.

`SpecificPeople` is **not** ordered against the relationship tiers — a grant can
outlive the relationship that existed when it was made. When either side of a
clamp is `SpecificPeople`, encode the exact write-time intersection as a
`SpecificPeople` allowlist rather than assuming a tier ordering that could widen
the item.

## Filtering happens in-query, before pagination

Every visibility, block, mute, and scope filter must be part of the database
query, applied **before** `cursorPaginate`.

Filtering a returned keyset page is a bug, not a shortcut. It produces short
pages, makes page size depend on where excluded rows happen to land, and corrupts
the cursor. This applies to new filters as much as existing ones.

Corollary: a filter that cannot be expressed in SQL is a design problem to solve
before the feature ships, not something to paper over in PHP.

## One visibility path per concept

A surface that shows posts uses the feed's visibility rules. It does not grow a
second, parallel implementation that happens to agree today. Where two surfaces
show the same kind of thing, they share the query builder.

The same applies to counts: a count displayed next to a listing must be computed
with the same visibility constraints as the listing, or the two will disagree and
the disagreement will leak moderation state. See
`Post::scopeWithEngagementCounts`, which counts only comments the viewer may see.

## Context never grants access

Browsing an item through a container — an Interest, a profile, a search result, a
tag — never widens what the viewer may see. A followers-only post stays
restricted when reached from a generally visible container.

Practically: apply the container filter *in addition to* the standard visibility
constraints, never *instead of* them.

## Blocking is asymmetric by design

`App\Support\BlockGraph` is the single source of truth. The asymmetry is
deliberate and load-bearing:

- **Denial** applies to the blocked account **as a whole**, across every identity
  the blocker owns. This is what prevents persona-based evasion.
- **Hiding** is observable by the blocker, so it crosses only *publicly linked*
  identities. It must never reveal that a Separate persona belongs to an account.

Do not "simplify" these into a single symmetric rule. See #173.

## Persona identity boundaries

A Separate persona's pseudonymity is independent of audience tier. Content may
not be composed in a way that links a Separate persona to the account identity or
to another Separate persona, at any audience level — see
`PostService::assertAttachmentIdentitiesMatch` and
`App\Support\ContentIdentity`.

The byline (`posts.character_id`, "Post as") and *attaching* a character's
profile card are different relationships with different rules. Do not conflate
them.

## Neutral failure

When a viewer lacks access, the response must not disclose *why*. In particular
it must never reveal block direction, persona linkage, the parent author or
title, the identity of attached content, or the container an item lived in.

Prefer 404 over 403 where 403 would confirm existence. `Controller::authorizeOr404`
exists for this.

A user who has lost access to a discussion still retains author-scoped control of
their own contributions — they can find and delete what they wrote without
regaining access to the parent. That control path must itself be neutral.

## See also

- [Moderation and account states](moderation.md)
- [Schema portability](schema-portability.md)
- `docs/media/moderation-and-privacy.md` for media-specific review flow
