# Profile & Personas — UX Recommendation

> Design study output. Decisions were taken by the product owner in an interview;
> the implementation plan is a proposal.
>
> **Revision 2** — incorporates a critical review that fact-checked the first
> draft against the code. Corrections are marked ⚠️ where the first draft was
> wrong. Follows on from
> [`ia-redesign-recommendation.md`](./ia-redesign-recommendation.md), which
> produced today's `/me`, and answers that document's open question #2.
>
> **Pre-launch.** The app has no users yet. There is no data to migrate, no
> bookmark to preserve, and no transition window to engineer. Migrations may be
> amended in place rather than layered; constraints should simply be defined
> correctly the first time. This removes rollout risk — it does **not** soften
> any design flaw, since a design flaw ships with the first real user.

## TL;DR

Today `/me` is the post-login landing page and it opens on the **feed**. Signing
in shows a to-do checklist and other people's posts — not your profile, not your
work, and nothing resembling what a visitor sees. Personas exist but are a filter
chip inside a card: no page of their own, no followers, no reach.

Six moves:

1. **Split the surfaces.** `/feed` becomes a real page again and the post-login
   landing. `/me` becomes a real self-profile — the visitor page plus edit
   affordances, with a showcase of your latest work above the fold.
2. **Promote personas to full profiles.** Own URL (`/c/{ulid}`), own bio, own
   interests, own media/posts, own followers — and the persona's name alone on
   the byline.
3. **Follow is one edge with a persona scope**, and **every consumer of the
   follow graph must become persona-aware.** This is the hard part; see
   [The persona-aware rule](#the-persona-aware-rule).
4. **Global identity switcher in the navbar,** hydrated from the existing
   `initial-data` payload — shipped together with the surfaces that consume it,
   and invisible until you have a persona.
5. **Publishing media auto-creates a Post** at moderation approval, so the feed
   carries real activity without a union query.
6. **Ship the explanation with the feature.** See [Inline help](#inline-help).

## Decisions taken

| # | Question | Decision |
|---|----------|----------|
| D1 | Landing surface | Profile-first `/me`; feed moves to `/feed` |
| D2 | Persona status | Full profiles with their own URL and followers |
| D3 | Follow direction | **Inbound only for v1** — personas are followed, they don't follow out |
| D4 | Persona reach | Following a persona grants **that persona's content only** — including feed membership and notifications (see [B1](#b1)) |
| D5 | Owner attribution | Per-persona **Linked / Separate**, default Linked |
| D6 | Subsumption | Following a person inherits their **Linked** personas' follower tier. **Separate** personas stand alone |
| D7 | Feed items | Auto-create a Post **on moderation approval**, not at upload |
| D8 | Switcher placement | Navbar, hydrated from Blade JSON; ships with its consumers |
| D9 | Persona-free users | Personas are an **opt-in layer**. Every surface must be complete for a user who never creates one |
| D10 | Byline | Persona content is bylined by the **persona alone** |

## Two graphs, two motives

- **The owner operates a set.** Ben manages Ben + Kira + Vex as one operator.
  Management surfaces are account-centric.
- **Everyone else meets a singular.** @alice cares about Kira. Ben is at best
  trivia, at worst noise she didn't ask for.

| | Human ↔ human | Viewer → persona |
|---|---|---|
| Motive | Affinity, friendship, shared interests | Audience, following the work |
| Direction | Naturally reciprocal | Naturally one-directional |
| Surface | `/me`, People directory, interest matching | `/c/{ulid}`, Explore |
| Currency | Mutual interests | Media, stories, role-play |

This is the real justification for **D3**: an audience relationship genuinely *is*
one-directional. It also answers whether personas hollow out the human profile —
they don't, because the affinity machinery in `FollowController::usersPayload()`
(`interest_match_score`, mutual-interest counts) is meaningless on a fictional
character and central to finding real people.

## Users without personas (D9)

Most users will never create a persona. They are the default case, not a degraded
one. **Personas stay invisible until opted into.**

| Surface | No personas | One or more |
|---|---|---|
| Navbar switcher | **Not rendered** — no dropdown, no chevron | Avatar dropdown |
| `/me` identity rail | **Not rendered.** One "You" tab plus "New" explains nothing and looks broken | Avatar tab rail with counts |
| Persona affordance | One quiet entry point | "New persona" in the rail |
| H1 switcher copy | Never fires | Persistent while active |
| Onboarding checklist | **No persona step** in `Onboarding::steps()` | unchanged |

The test: **would a user who will never create one notice it?** If yes, it's in
the wrong place.

## Current state

`resources/js/user/follow-profile.tsx` serves both the visitor profile and the
owner's, branching on `is_self`: header card, interests, identity strip, and tabs
`Home (feed) · Media · Stories · Posts · Favorites`. The owner defaults to `feed`
(`:246`).

### Gaps

| # | Gap | Evidence |
|---|-----|----------|
| 1 | **You land on the feed, not your profile.** | `StaticPageController@home`, `follow-profile.tsx:246` |
| 2 | **Nothing shows what a visitor sees.** Owner and visitor renders diverge with no preview. | `follow-profile.tsx` |
| 3 | **Users have no bio.** No free-text field at all; personas have `description`. | `User::$fillable` |
| 4 | **Personas have no URL and no followers.** `/api/characters/*` is owner-CRUD; the follow graph is user-to-user. | `routes/web.php`, `FollowGraph` |
| 5 | **Personas have no `ulid`.** Media and Story both have one. | `create_characters_table` |
| 6 | **The feed has no mixed mode.** New users land on an empty feed. | `FeedController::payload()` |
| 7 | **The feed carries only manual posts.** `Post` rows are created only in `PostService::create()`. | — |
| 8 | **No follower/following counts** on any profile. | `profilePayload()` |
| 9 | **The switcher doesn't persist** — page-local `useState`. | `follow-profile.tsx:244` |
| 10 | ⚠️ **WITHDRAWN.** The first draft claimed `Audience::Mutuals` was broken for personas and proposed migrating rows to `Followers`. **Both were wrong.** `HasPrivacyPolicy.php:174` resolves `Mutuals` as `FollowGraph::mutual($viewer->id, $this->user_id)` — the *owner's* human graph — so the tier is well-defined and works today. The proposed migration would have **widened** access (followers ⊃ mutuals). Keep Mutuals in the persona audience picker; drop the H4 help copy. | `HasPrivacyPolicy.php:174` |
| 11 | **Persona posts are bylined "Kira via Ben"** — but *not* unconditionally: `PostPresenter::asCharacter()` returns `null` when the viewer fails the character's own audience, and `PostCard` then falls back to the human name. Both paths need fixing. | `PostCard.tsx:194–196`, `PostPresenter.php:106–111` |
| 12 | **Personas cannot own stories.** There is no `stories.character_id`; `ProfileContentController::storiesQuery()` selects by `StoryInvolvement` and **drops the `user_id` constraint**, so it can list other authors' stories. | `ProfileContentController.php:149–161` |
| 13 | **Personas cannot be reported.** `StoreReportRequest` allows only `media\|story\|post`. Neither can users. | `app/Http/Requests/Report/StoreReportRequest.php` |
| 14 | **`FavoriteService::canSeeCharacter()` is a second, drifting copy** of character visibility: it resolves `SpecificPeople => false` where `Character::isViewableBy()` consults the allowlist, and its "no allowlist table" comment is stale. | `app/Services/Favorites/FavoriteService.php` |

## The identity model

### Two layers

`ProfileGate::canView()` evaluates: null viewer → false; then self/admin bypass;
then the audience tier. So:

- **Access** — "may I see this?" — is a fact about **human accounts**.
- **Identity** — "who is this from?" — is a fact about **personas**, purely
  presentational.

The owner seeing all their personas is the self bypass, extended from `target->id`
to `character->user_id`. Access must stay account-level: the owner bypass resolves
to an account, moderation resolves to a human, and a persona-level ban invites a
new persona.

### The follow edge

```
FollowRequest
  requester_id           -> users.id       the human doing the following
  recipient_id           -> users.id       the human who owns the target
  recipient_character_id -> characters.id  NULLABLE, cascadeOnDelete
                              NULL = "the whole person"
                              set  = "just this persona"
```

⚠️ **`cascadeOnDelete`, not `nullOnDelete`.** The repo's convention for character
FKs is `nullOnDelete` (`media.character_id`), but applied here, deleting a persona
would silently convert every persona-only follower into a **full account
follower**. Uniqueness is `(requester_id, recipient_id, recipient_character_id)`
— but a nullable column makes NULLs distinct in both MySQL and SQLite, so
duplicate `(alice, ben, NULL)` rows stay insertable. Use a coalesced/generated
column or an equivalent guard.

⚠️ **`requester_character_id` is dropped from v1.** The first draft added it
unpopulated to "avoid a migration rewrite." Adding a nullable column later is one
trivial migration; an unused column invites accidental reads.

### <a id="the-persona-aware-rule"></a>The persona-aware rule

**This is the crux, and the first draft got it wrong.**

<a id="b1"></a>Every consumer of the follow graph currently matches on
`recipient_id` alone. Once persona-scoped edges exist, each such consumer
**silently treats a persona follow as an account follow**. The first draft said
the feed was account-level and this "falls out rather than being special-cased."
It does not. Concrete failure:

> @alice follows **only Vex** (Separate). Her edge is `(alice → Ben, character =
> Vex)`. `FeedController` matches `recipient_id` only, so **Ben's** posts and
> **Kira's** posts appear in her Following feed. She followed one pseudonymous
> persona and the human surfaced — exactly the inference D6 exists to prevent.

`NotifyFollowersOfPost` has the identical bug: it selects followers by
`recipient_id` with no character scoping, so a Vex-only follower is notified
"Ben posted."

**The rule:** a persona-scoped edge admits **only that persona's content**, for
access, feed membership, and notifications alike. Every call site below must be
audited and given persona context — or explicitly denied it, with a comment
saying why.

| Call site | Concern |
|---|---|
| `HasPrivacyPolicy.php:103, 111, 114, 173` | Item-level gates; needs the outer row's character column — and for a `Character` *as* the item, that column is `characters.id` itself |
| `ProfileGate.php:34–35` | Single profile check |
| `ProfileGate::canViewMany()` | Plucks `recipient_id` with **no character column** — would treat every persona edge as an account follow |
| `FollowController.php:164–165` | Mutuals filter in the directory |
| `FollowController.php:234` | `->first()` assumes one row per pair; must filter `whereNull('recipient_character_id')` — as must `can_follow_back`, `requestFollow`, and the inbox |
| `FeedController.php:47` | Feed membership ([B1](#b1)) |
| `FavoriteService.php` | `canSeeCharacter()` — already drifting (gap 14); consolidate *before* this change makes it a third path |
| `Onboarding::steps()` | `is_following` |
| `NotifyFollowersOfPost.php` | Fan-out membership |

The correlated-subquery form additionally needs a join to `characters.is_linked`
for subsumption. **`FollowGraph` is the single auditable source of truth in both
its boolean and SQL forms** — its own docblock says so. The rule must live there
and nowhere else, or the two paths drift, and a drift here is a leak, not a
display bug.

### Linked vs Separate (D5 + D6)

| | Linked | Separate |
|---|---|---|
| Owner shown on the persona page | Yes, as page meta | No |
| Your followers see its followers-only posts | Yes (subsumption) | No |
| Starts with | Your existing audience | Zero followers |

**Why Separate can't inherit.** If following Ben granted Vex's followers-only
content, @alice reasons: *"I never followed Vex, yet I can see Vex's
followers-only posts — Vex must belong to someone I follow."* With a small follow
list that identifies Ben.

### <a id="b3"></a>Separate is a lie until these are scrubbed

⚠️ **Existing surfaces already expose the persona→owner link.** Shipping the
Separate toggle before fixing all of these ships a written guarantee the system
does not honor:

| Surface | Leak |
|---|---|
| `FollowController::charactersStrip()` | Enumerates **every** persona's name + avatar to any viewer passing the profile gate — deliberately, per its docblock |
| `ProfileContentController::scopeToIdentity` | Persona content tabs live on the **human's** profile |
| `FavoriteService` | A favorited character's `href` points at `/users/{owner_id}` |
| `StoryInvolvement` | Involvement-tagged stories tie a character to the owner |
| `/c/{ulid}` 403-vs-404 | A "hidden but exists" response is an existence oracle. Use the `first()` + generic 404 + `authorizeOr404()` pattern that Media already uses (and that #85 is applying to Story/Post) |
| `inherit_interests` | A Separate persona in the directory carries the owner's exact interest signature — a correlation vector |

### Bylines (D10)

Invert `PostCard.tsx:194–196`. The human never appears in a byline.

⚠️ **Specify the fallback.** `PostPresenter::asCharacter()` currently returns
`null` when the viewer fails the character's audience, and `PostCard` falls back
to the human name — leaking precisely what D10 hides. After inversion, the byline
must show the **persona name only, unlinked**, never the human.

## Information architecture

| Route | Purpose | Change |
|---|---|---|
| `/feed` | The feed. Post-login landing. | **Restored as a real page** (#98) |
| `/me` | Self-profile. | Repurposed |
| `/users/{user}` | Someone else's profile. | Unchanged |
| `/c/{ulid}` | A persona's public profile. | **New** |

⚠️ **Not `/home`** — `route('home')` is the guest marketing splash
(`routes/web.php:42`). `/dashboard` repoints to `feed`; **`/characters` stays
pointed at `/me`**, since personas are still managed there.

### `/me` layout

```
┌──────────────────────────────────────────────┐
│ [avatar]  Ben                                │
│           bio / tagline                      │
│           [user type] [gender] [pronouns]    │
│           12 followers · 8 following         │
│                        [Edit] [👁 View as ▾] │
├──────────────────────────────────────────────┤
│  ╭────╮  ╭────╮  ╭────╮  ╭────╮              │  ← omitted entirely
│  │ You│  │Kira│  │ Vex│  │New │              │    with no personas
│  ╰━━━━╯  ╰────╯  ╰────╯  ╰────╯              │
├──────────────────────────────────────────────┤
│ Latest ▸ [img][img][img][story][post]        │
├──────────────────────────────────────────────┤
│ Media 24 · Stories 3 · Posts 11 · Favorites 6│
└──────────────────────────────────────────────┘
```

Three changes carry the value: the owner default tab becomes the **showcase**
(the `feed` tab is removed entirely); a **"Latest" strip** mixes recent media,
stories, and posts so the page showcases your work on arrival; the identity strip
becomes a real **tab rail**, absent for persona-free users.

### The persona switcher (D8)

The navbar already hydrates from the Blade `initial-data` script. Extend that
payload with `identities` and `activeIdentityId`; persist via a small
`POST /api/identity` session key.

⚠️ **Ship it with its consumers.** The first draft put the switcher in Phase 1
while the composer, upload dialog, and story editor still take `character_id`
from client-local state — so its help copy ("New posts, uploads, and stories will
be from Kira") would have been false on day one. The switcher and those three
surfaces ship together.

The session key is **authorship state only**. It must never enter a privacy
decision — assert in tests that the gate decides identically regardless of
`active_character_id`.

## Feed

### Mixed vs Following

Add a `scope` parameter: `following` (today's behaviour) and `mixed` (default,
adding `discoverable` + `Everyone` posts from any active user). `viewableBy()`
still applies, so mixed widens *membership*, never access.

⚠️ **Moderation note.** Posts are auto-approved at creation
(`PostService.php:74`) while media and stories are pre-moderated. Mixed-by-default
therefore turns every new account's feed into a distribution channel for
unreviewed strangers' posts. `FeedController` already requires others' posts to be
Approved — confirm that reactive moderation is still sufficient at this volume.

### Auto-post on publish (D7)

⚠️ **Announce at moderation approval, not at upload.** Media and stories are
pre-moderated; posts are auto-approved. Announcing at upload creates an Approved
post wrapping a Pending attachment that `PostPresenter::canSee()` hides —
followers see an empty shell until an admin approves.

**An announcement post has no privacy of its own.** It carries the item's
`audience`, `discoverable`, and `SpecificPeople` allowlist — omitting the
allowlist would make the post visible to nobody — and an observer propagates any
later change to the item, so the two can never diverge.

Implemented as **copy-and-propagate**, not a nullable `posts.audience` resolved at
read time. The literal form is the truer single source of truth, but
`scopeViewableBy()` is a correlated subquery on the hot, keyset-paginated feed
path, and `PostAttachment` is polymorphic — a null audience would mean branching
on `attachable_type` to join `media` or `stories`, then a second polymorphic hop
to reach the item's `audience_members` for the `SpecificPeople` tier, duplicating
the audience rule per attachable table. Copying gives the identical guarantee and
leaves the feed query untouched.

**The general invariant: a post may never be broader than its most restrictive
attachment.** Enforce the clamp at write for *all* posts, not just announcements.
A manual public post attaching a followers-only photo currently renders as an
empty shell, because `PostPresenter::canSee()` nulls the attachment out.
Announcements are simply the degenerate case where the post has no opinion of its
own.

Deleting the post never deletes the item, and vice versa. Re-publishing does not
re-announce — guard on an existing attachment for that item.

⚠️ **Stories cannot carry `character_id`** (gap 12). Either add a real authorship
column or exclude stories from auto-posting in v1.

## Inline help

The access model reduces to two sentences:

> **Following someone follows everything they make.**
> **Following a persona follows just that persona.**

| # | The wrong guess | Severity |
|---|-----------------|----------|
| H1 | *"Switching to Kira means I'm browsing as Kira"* — every other switcher users have met (Google, Slack, Instagram) **does** switch context; ours changes authorship only | **High** |
| H2 | *"I followed Kira, so I'm following Ben"* | Medium |
| H3 | *"Why is my upload in the feed? Does deleting the post delete the photo?"* | Medium |
| ~~H4~~ | ⚠️ **Withdrawn** — rested on the incorrect gap 10 | — |

**The subsumption asymmetry does not appear here, and that's the design working.**
@alice never observes an inconsistency, because she doesn't know Vex is connected
to Ben. Only the **owner** needs the rule, at the toggle, where it reads as
intuitive: *a secret identity builds its own audience.*

### Pattern

`PrivacyBadge` establishes the house style: an icon opening a `Popover` with a
bold label and a plain line, rendered owner-only. Generalise to `<HelpHint>`.
Persistent inline text for H1 (a rule this counter-intuitive can't hide behind a
click); popover for visible controls; dismissible first-run for the persona
explainer; **never a bare tooltip** — no hover target on touch.

### Copy deck

**H1 — the switcher.** Only when a persona is active.
> **Creating as Kira.** New posts, uploads, and stories will be from Kira. What
> you can see doesn't change.

In the dropdown footer:
> Switching changes who you create as — never what you can see.

**H2 — persona follows.** Visitor-facing, beside the Follow button:
> You'll see what Kira posts. To see everything Ben makes, follow Ben too.
> *(second sentence only when Linked)*

On the Linked / Separate control:
> **Linked** — People visiting Kira can see she's yours, and anyone who follows
> you will also see Kira's followers-only posts.
>
> **Separate** — Nobody can tell Kira is yours. She builds her own following from
> scratch.

First-run persona explainer (the only persona copy a persona-free user sees):
> **Personas** are characters you create — for fiction, art, and role-play. Each
> gets its own page, its own followers, and its own media. Your real profile
> stays separate. Most people never need one.

**H3 — auto-posts.** Fires for everyone:
> Share this to your followers' feeds. Unchecking keeps the upload private to your
> profile.

On delete:
> This removes the post from feeds. Your photo stays in your library.

**View as:**
> Viewing your profile as **someone who doesn't follow you**. This is exactly what
> they see.

**Feed scope:**
> **Mixed** — public posts from everyone, plus the people and personas you follow.
> **Following** — only the people and personas you follow.

### Where help is *not* needed

No copy for the account-vs-persona access layering or the human recipient on the
edge. These are implementation facts whose exposure would undermine the
pseudonymity the model protects.

## Phased plan

Gates for every phase:
`pnpm run type-check && lint && test && build` and
`./vendor/bin/pint --test && composer test`.

**Inline help ships with the control it explains.** A phase that lands the
switcher without H1 has not landed.

### Phase 0 — Feed split + bio fields → issue #98 *(in progress)*

`/feed` restored as a real page; `/` and `/dashboard` repointed; `users.bio` and
`users.pronouns` added (fields only, no UI); the pinned tests updated. No schema
beyond the one migration, no privacy surface.

### Phase 1 — Make `/me` a profile *(design-dense)*

Drop the `feed` tab; default the owner to a showcase; add the "Latest" strip;
identity strip → avatar tab rail, **omitted with no personas**; surface
`bio`/`pronouns` in the header and identity editor; `<HelpHint>` generalised from
`PrivacyBadge`; move `OnboardingChecklist` to `/feed` with **no persona step**.

No schema, no gate changes.

### Phase 2 — Consolidate character visibility *(prerequisite for Phase 4)*

Collapse `FavoriteService::canSeeCharacter()` into `Character::isViewableBy()`
(gap 14) so Phase 4 doesn't create a third divergent path. Add persona (and user)
targets to `StoreReportRequest` (gap 13). Pure refactor + tests.

### Phase 3 — Personas as public profiles

`characters.ulid` and `is_linked` (amend the original migration — pre-launch);
`GET /c/{ulid}` using the `first()` + generic-404 pattern; persona mode in the
profile component; **byline inversion** including the audience-fail fallback;
Linked/Separate control + H2 copy; personas in Explore/People.

**Ships with the Phase 4 scrubbing**, or the Separate guarantee is false — see
[Separate is a lie until these are scrubbed](#b3).

### Phase 4 — Persona follows *(privacy-critical)*

`follow_requests.recipient_character_id` with `cascadeOnDelete` and NULL-safe
uniqueness; the persona-aware rule applied to **all nine call sites**; subsumption
inside `FollowGraph` only; follow lists rendered by edge identity; notification
fan-out scoped by edge; the [B3](#b3) surface scrubbing.

Tests before UI, including the negative assertions: a follower of the owner must
**not** see a Separate persona's followers-only content, must **not** receive its
notifications, and must **not** see the owner's posts from a persona-only edge.

### Phase 5 — Feed composition

`scope=mixed|following`; auto-post at **approval**, carrying the allowlist;
"Announce this" checkbox + H3 copy. Resolve the stories-authorship question
(gap 12) or exclude stories.

### Phase 6 — View as

Public / Follower simulation running the real gate. ⚠️ **Cut "a specific person"
from v1** — it requires plumbing a viewer override through every payload builder
for marginal value over the two-tier simulation.

### Switcher — ships with its consumers

Navbar switcher + `POST /api/identity` + H1 copy, landed together with the
composer, upload dialog, and story editor reading the session identity. Not
Phase 1.

## Risks & open questions

| Risk | Mitigation |
|---|---|
| **The persona-aware rule has two implementations** (boolean + subquery). Drift = a leak. | Keep it in `FollowGraph`; test both forms against identical fixtures including the Separate case |
| **A call site missed in the nine-site audit** silently widens access | Make the inventory a checklist in the Phase 4 issue; require a comment at any site explicitly denied persona context |
| **Persona session state leaking into privacy decisions** | Authorship only; assert the gate is invariant to `active_character_id` |
| **Persona pages multiply the moderation surface** | Admin views resolve `character.user_id` regardless of Linked/Separate; gap 13 must land first |
| **Blocking (not yet built) collides with Separate personas** — if @alice blocks Kira and Ben's content vanishes, she infers the link. Blocking must be account-level to prevent evasion. | Decide deliberately when blocking is designed |
| **Mixed feed distributes unreviewed posts** | Confirm reactive moderation suffices, or pre-moderate posts |

**Open questions:**

1. **Persona interests** — own, or inherited? `inherit_interests` exists, but a
   Separate persona inheriting the owner's signature is a correlation vector.
2. **Story authorship** (gap 12) — add `stories.character_id`, show
   involvement-tagged stories including other authors', or exclude stories from
   persona pages?
3. **Persona follow lifecycle** — auto-accept or pending? There is no per-persona
   `profile_audience` equivalent, and the request notification copy needs framing.
4. **Linked meta vs owner profile audience** — "a persona of @ben" on a public
   persona page reveals Ben to viewers who fail *his* profile gate. Probably
   acceptable for Linked, but it should be a decision.

*Resolved:* personas appear in the People directory (per the two-graph framing).
