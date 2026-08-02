# Moderation and account states

## Publish immediately, moderate retroactively

Short-form user content — posts and comments — **publishes immediately** and is
moderated reactively. There is no pre-publication queue for them. A feed that
only fills after admin review is dead on arrival, so the trade is deliberate:
publish fast, take down fast.

Media and stories are different: they gate on review before they can be attached,
announced, or played. See `docs/media/moderation-and-privacy.md`.

The shared plumbing is the `Moderatable` trait (`moderation_status`,
`moderated_by_user_id`, `moderated_at`, `moderation_notes`). Content authored by
the viewer is visible to that viewer in **any** review state — an author can
always see that their own item was rejected. Everyone else sees approved content
only.

## Removal paths are distinct and must stay distinct

There is more than one way for a contribution to stop being visible, and
collapsing them loses information the product depends on:

| Path | In-thread result | Author's own view |
| --- | --- | --- |
| **Author deletion** | Tombstone if it has visible replies; removed outright if a leaf | Gone |
| **Owner removal** (post owner moderating their own discussion) | Tombstone, actor recorded | **Still listed** — the author is not silently erased |
| **Admin rejection** | Hidden outright; orphaned replies hidden with it | Still visible to the author, marked rejected |

Admin rejection deliberately produces **no tombstone**. A tombstone advertises
that moderation occurred, which is exactly what an admin takedown should not do.

One user's deletion must never delete another user's content. Replies are never
silently promoted when their parent goes away — either the parent leaves a
tombstone to hold the context, or the replies are hidden with it.

Internal retention for abuse reports, moderation, or legal/audit purposes is
separate from the user-facing deletion contract. "Deleted" to a user means "no
longer visible in the discussion."

## Account states

These are independent levers, not a ladder:

| State | Column(s) | Effect |
| --- | --- | --- |
| Deactivated | self-service | User-initiated; reversible via `account.reactivate` |
| Disabled | `is_disabled` | Admin kill switch; `EnsureApproved` rejects outright |
| Banned | `banned_at`, `ban_reason`, `banned_by_user_id` | Stays logged in; `EnsureNotBanned` gates to the ban notice, appeal, deactivate, delete |
| Ban hides content | `ban_hides_content` | Optional. Off = memorialized, on = content hidden |
| Legal hold | `legal_hold_at` | Admin-only. Blocks account **deletion** regardless of ban |
| Scoped restriction | `user_restrictions` | Per-capability, does not remove the account |

`User::active()` and `User::scopeActive()` encode the composite "is this account
currently presentable" rule. Query through them rather than re-deriving the
condition, or a new surface becomes a bypass for a state the per-record policies
would reject.

## Scoped restrictions

`App\Enums\RestrictionCapability` withdraws one capability without removing the
account: `media.upload`, `media.view`, `comment.create`. Rules that hold for all
of them:

- **Historical, never destructive.** Lifting sets `lifted_at` /
  `lifted_by_user_id`; rows are not deleted. Expiry is evaluated on read
  (`UserRestriction::scopeActive`) — there is no scheduler on shared hosting.
- **Enforce at every layer independently.** `media.view` filters the query
  *and* gates the asset and HLS routes. Query-layer filtering alone is bypassed
  by requesting the object URL directly.
- **A restriction never touches the user's own content.** Their media stays
  visible to them and stays in their export.
- Go through `RestrictionGate`, which memoizes per user per request.

Restrictions are transparent to their subject — see below.

## Self-service data access survives every state

A user must be able to reach, export, and delete their own data in **every**
account state, including banned and restricted. This is a standing commitment,
not a per-feature decision.

Concretely, these must stay reachable:

- `/api/account/export`
- the user's own activity listing
- author-scoped deletion of their own posts and comments

Legal hold blocks account *deletion*. It must never block data *download*.

When adding a gate — middleware, policy, capability check — verify it does not
catch these paths. `EnsureNotBanned` maintains explicit route-name and
path-prefix allowlists for exactly this reason.

## Admin sanctions are transparent to their subject

Unlike blocking, an admin sanction has no counterparty to protect. The restricted
or banned user is told what applies to them, why if a reason was given, when it
expires if it does, and how to appeal.

Do not apply the deny-versus-hide asymmetry from
[privacy-and-visibility.md](privacy-and-visibility.md#blocking-is-asymmetric-by-design)
to admin action. A user who cannot see why an action fails cannot appeal it.

## See also

- [Privacy and visibility](privacy-and-visibility.md)
- `docs/media/moderation-and-privacy.md`
