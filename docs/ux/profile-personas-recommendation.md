# Profile & Personas — UX Recommendation

> Design study and implementation record. Decisions were taken by the product
> owner in an interview and reconciled here with the shipped system.
>
> **Revision 3** — reconciles the recommendation with the implementation that
> shipped. Corrections are marked ⚠️ where an earlier draft was wrong. Follows
> on from
> [`ia-redesign-recommendation.md`](./ia-redesign-recommendation.md), which
> produced today's `/me`, and answers that document's open question #2.
>
> **Deployed.** This document began as a pre-launch study, but the subsystem is
> now live. Its original in-place migration advice is historical and no longer
> applies: schema changes must be additive so already-deployed databases receive
> them.

## TL;DR

The redesign is shipped. `/feed` is the post-login landing page; `/me` is the
self-profile. Personas have their own pages, follow scopes, content identity,
and privacy boundary.

The implementation rests on six moves:

1. **Split the surfaces.** `/feed` is a real page and the post-login landing.
   `/me` is a real self-profile — the visitor page plus edit
   affordances, with a showcase of your latest work above the fold.
2. **Promote personas to full profiles.** Own URL (`/c/{ulid}`), own bio, own
   interests, own media/posts, own followers — and the persona's name alone on
   the byline.
3. **Follow is one edge with a persona scope**, and **every consumer of the
   follow graph is persona-aware.** This is the hard part; see
   [The persona-aware rule](#the-persona-aware-rule).
4. **Global identity switcher in the navbar,** hydrated from the existing
   `initial-data` payload — shipped together with the surfaces that consume it,
   and invisible until you have a persona.
5. **Publishing media or eligible stories auto-creates a Post** at moderation
   approval, so the feed carries real activity without a union query.
6. **The explanation ships with the feature.** See [Inline help](#inline-help).

## Decisions taken

| # | Question | Decision |
|---|----------|----------|
| D1 | Landing surface | Self-profile at `/me`; post-login feed at `/feed` |
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

## Baseline state (historical)

The tables below record the gaps the epic started from. They are retained as
design rationale, not as a description of the current product. The phased plan
later in this document records the shipped state.

### Baseline gaps (closed)

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

### Active authoring identity

`ActiveIdentity` owns the server-side authoring choice. The navbar hydrates the
available identities and active id from Blade; `POST /api/identity` accepts only
a non-deleted persona owned by the signed-in user and stores its id in the
session under `active_character_id`. A missing, stale, or deleted session id
resolves back to the human identity. A foreign id submitted to the endpoint gets
the same generic 404 as a missing one, so the switch cannot become an ownership
oracle.

The shared frontend identity store keeps independently mounted React roots in
sync. The post composer and media upload dialog read it directly; new-story
creation resolves the same session value on the server and writes it to the
owner's `story_authors` row. An existing story author may choose their own
persona on that per-author row.

This is **authorship state only**. `ActiveIdentity` is not an input to
`ProfileGate`, `HasPrivacyPolicy`, or ordinary `FollowGraph` decisions. Tests run
the same privacy fixtures under different active identities to pin that
separation.

### Persona discovery preferences

A persona has one identity-level discovery choice: `discoverable` controls
whether an Everyone-audience persona is listed in Explore and People search or
remains reachable only by direct link. It defaults independently on the persona
and is never copied from the owner's account settings.

Persona `preferred_user_types` and `preferred_genders` are not supported editing
fields. Those names describe what an identity wants to see, but personas do not
browse; switching identity never changes the viewing context. Reinterpreting
them as "show this persona to..." would introduce a new targeting and
correlation surface, so persona discovery remains interest-based. The existing
nullable database columns are dormant legacy schema and are not accepted or
returned by the persona management API.

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
duplicate `(alice, ben, NULL)` rows stay insertable. The shipped schema therefore
adds the virtual generated column
`recipient_scope_id = COALESCE(recipient_character_id, 0)` and makes
`(requester_id, recipient_id, recipient_scope_id)` unique. Zero cannot be a real
character id, so account and persona scopes are both NULL-safe on MySQL and
SQLite.

⚠️ **`requester_character_id` is dropped from v1.** The first draft added it
unpopulated to "avoid a migration rewrite." Adding a nullable column later is one
trivial migration; an unused column invites accidental reads.

### <a id="the-persona-aware-rule"></a>The persona-aware rule

**This is the crux, and the first draft got it wrong.**

<a id="b1"></a>The implementation had to audit every consumer that originally
matched `recipient_id` alone. Otherwise each would have **silently treated a
persona follow as an account follow**. The first draft said the feed was
account-level and this "falls out rather than being special-cased." It does not.
The failure the shipped rule prevents is:

> @alice follows **only Vex** (Separate). Her edge is `(alice → Ben, character =
> Vex)`. `FeedController` matches `recipient_id` only, so **Ben's** posts and
> **Kira's** posts appear in her Following feed. She followed one pseudonymous
> persona and the human surfaced — exactly the inference D6 exists to prevent.

**The rule:** a persona-scoped edge admits **only that persona's content**, for
access, feed membership, and notifications alike.

| Call site | Shipped treatment |
|---|---|
| `HasPrivacyPolicy` | Supplies the outer row's character column; for a `Character` item that is `characters.id` itself |
| `ProfileGate` and `canViewMany()` | Human-profile checks accept only account-scoped edges |
| `FollowController` | Human mutuals, follow-back, requests and inbox queries explicitly require account scope; persona endpoints pass persona context |
| `FeedController` | Passes `posts.character_id` into the correlated membership rule ([B1](#b1)) |
| `FavoriteService` | Delegates persona visibility to `Character::isViewableBy()` |
| `Onboarding::steps()` | Human-profile follow state requires an account-scoped edge |
| `NotifyFollowersOfPost` | Fans out with `FollowGraph::followersOfIdentity()` using the post's persona context |

The correlated-subquery form additionally needs a join to `characters.is_linked`
for subsumption. **`FollowGraph` is the single auditable source of truth in both
its boolean and SQL forms**. The rule lives there and nowhere else; parity tests
exercise identical human, Linked, and Separate fixtures because drift here is a
leak, not a display bug.

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

### <a id="b3"></a>The Separate boundary

⚠️ **A persona page is not private if a surface one click away identifies its
owner.** The shipped implementation treats the whole reachable surface as one
boundary:

| Surface | Shipped contract |
|---|---|
| Human profile and identity strip | Visitors never discover a Separate persona through its owner; persona content lives at `/c/{ulid}` |
| Posts, stories, comments, favorites and Explore | Visitor presenters suppress cross-identity attachments and owner links; persona-authored comments remain persona-framed |
| Follow dialogs | Show people who follow the identity, not account-vs-persona edge mechanics |
| Notifications | Carry the public persona name and content URL without a human actor id |
| Reports | Target the public persona/content entity while moderation resolves the owner only on the admin surface |
| `/c/{ulid}` and persona-reachable records | Missing and hidden records share a generic 404 |
| Interests | Linked personas may inherit; Separate personas have an explicit, initially empty rating set |
| Media payloads and assets | `MediaPresenter::visitorView()` omits original filenames and review state. `MediaResponseService::visitorItem()` emits app-relative asset URLs containing only the media ULID; `MediaAssetController` re-authorizes and streams the object so legacy storage keys containing the uploader id never reach the visitor |

The visitor contract is deliberately separate from `ownerView()` and
`adminView()`. Owner/admin surfaces retain management metadata and human
attribution. Visitor media detail frames a Separate item with the persona name,
avatar, and `/c/{ulid}` link; it never assembles an uploader block ad hoc.
`ContentIdentity` also rejects new cross-identity attachments and scrubs legacy
rows on read. A cross-surface feature guard walks a Separate persona's reachable
profile, media, post/comment, favorite, follow, notification, and asset surfaces
and asserts that the owner's id, name, profile URL, filename, and storage-key
correlators are absent.

### Bylines (D10)

The human never appears in a persona byline. `PostPresenter` omits the account
author from persona-authored visitor payloads. When the viewer fails the
persona's own audience, the fallback is the **persona name only, unlinked** —
never the human. Admin presentation restores the human account for moderation.

### Story authorship

Stories remain owned and moderated by one human account, but authorship identity
is per author. Each `story_authors` row has a nullable `character_id`: null means
that co-author writes as themselves; a value means that co-author writes as a
persona they own. The foreign key cascades on persona deletion, and
`UpdateStoryAuthorIdentityRequest` scopes validation to the author so one
co-author cannot claim another person's persona. Public presenters expose the
selected author identity without changing the story's account-level privacy
owner.

## Information architecture

| Route | Purpose | Change |
|---|---|---|
| `/feed` | The feed. Post-login landing. | **Restored as a real page** (#98) |
| `/me` | Self-profile. | Repurposed |
| `/users/{user}` | Someone else's profile. | Unchanged |
| `/c/{ulid}` | A persona's public profile. | **New** |
| `/personas/new` | Create a persona, then continue on its stable editor. | **New** |
| `/c/{ulid}/edit` | Owner-only persona editor. | **New** |

⚠️ **Not `/home`** — `route('home')` is the guest marketing splash
(`routes/web.php:42`). `/dashboard` repoints to `feed`; **`/characters` stays
pointed at `/me`** as a legacy bookmark, while persona creation and editing use
their dedicated pages.

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

The navbar hydrates `identities` and `activeIdentityId` from the Blade
`initial-data` payload and persists changes through `POST /api/identity`. It is
not rendered for persona-free users. The navbar, post composer, upload dialog,
and story creation path shipped together, so the H1 help copy describes real
behavior rather than a future promise. See [Active authoring
identity](#active-authoring-identity) for the server contract.

The session key is **authorship state only**. It must never enter a privacy
decision; tests assert that the gate decides identically regardless of
`active_character_id`.

### View as

Owner-only `view_as=public|follower` preview runs the real privacy stack.
`ViewAsMode` is the typed two-mode contract. `ViewAsContext` validates that the
signed-in user owns the exact human/persona profile being previewed, requires the
session's active identity to match a persona preview, and returns a generic 404
for invalid modes or identity mismatches.

The context supplies a normal, non-admin `User` model with synthetic id `0`.
That impossible id is safer than a nullable viewer or a boolean "pretend"
branch: null means unauthenticated in existing gates and would bypass the
authenticated-viewer paths being tested, while an out-of-band flag invites each
presenter to implement its own approximation. Id 0 instead forces
`ProfileGate`, `HasPrivacyPolicy`, owner/admin checks, allowlists, and correlated
SQL scopes to execute normally without accidentally matching a real account,
favorite, reaction, or stored follow edge.

Only `FollowGraph` recognizes the request-scoped simulation. Public supplies no
edge. Follower supplies one one-way edge to the previewed human or persona; an
account preview subsumes Linked personas but never Separate ones, and it never
implies the reverse edge required by Mutuals. The boolean and correlated-query
forms share that context and have parity tests. Preview mode disables mutations
and never changes `active_character_id`.

## Feed

### Mixed vs Following

`FeedController` accepts `scope=following|mixed`.

| Scope | Membership |
|---|---|
| `following` | The viewer's own posts plus posts from account/persona identities they follow |
| `mixed` | Everything in Following, plus discoverable, Everyone-audience posts from any active user |

`following` is the settled default. Missing and unknown values also fail closed
to Following; Mixed is an explicit opt-in because posts are reactively
moderated. The UI stores the choice in the URL and sends it on every cursor
request. Both scopes still require active authors, approval for other people's
posts, and `Post::scopeViewableBy()`, so Mixed widens *membership*, never access.

### Auto-post on publish (D7)

⚠️ **Announce at moderation approval, not at upload.** Media and stories are
pre-moderated; posts are auto-approved. Announcing at upload creates an Approved
post wrapping a Pending attachment that `PostPresenter::canSee()` hides —
followers see an empty shell until an admin approves.

The persisted controls make retries and observer runs deterministic:

- `media.announce_on_approval` and `stories.announce_on_approval` are the
  per-item preferences. New gallery uploads default the visible checkbox on;
  new stories opt in at creation. The migration defaults existing content to
  false, so deployment never retroactively announces old items.
- `posts.is_announcement` marks the generated wrapper, distinguishes it from a
  manual share of the same item, and lets synchronization find the one canonical
  announcement.

**An announcement post has no independent privacy opinion.** It copies the
item's `audience`, `discoverable`, and `SpecificPeople` allowlist — omitting the
allowlist would make the post visible to nobody — and observers propagate later
item changes. A pending, rejected, unpublished, deleted, or opted-out item cannot
leave a publishable announcement behind.

Implemented as **copy-and-propagate**, not a nullable `posts.audience` resolved at
read time. The literal form is the truer single source of truth, but
`scopeViewableBy()` is a correlated subquery on the hot, keyset-paginated feed
path, and `PostAttachment` is polymorphic — a null audience would mean branching
on `attachable_type` to join `media` or `stories`, then a second polymorphic hop
to reach the item's `audience_members` for the `SpecificPeople` tier, duplicating
the audience rule per attachable table. Copying gives the identical guarantee and
leaves the feed query untouched.

**The general invariant: a post may never be broader than any attachment.**
`PostService` enforces this at write for all posts, not just announcements.
Ordered relationship tiers clamp to the stricter tier and discoverability is the
logical intersection. `SpecificPeople` is not treated as merely "most
restrictive": all specific allowlists are intersected, then every candidate is
filtered through the requested relationship tier and every attachment's real
privacy check. For example, a Followers post attaching a SpecificPeople item
stores only allowlisted users who currently pass the Followers rule. This exact
intersection avoids widening access when an allowlist grant and relationship
tier disagree.

Deleting the post never deletes the item, and vice versa. Re-publishing does not
re-announce — guard on an existing attachment for that item.

Stories carry persona authorship on `story_authors.character_id`, not on the
`stories` row. Their privacy remains account-scoped. Eligible account/Linked
story announcements therefore use the account privacy identity; a story whose
owner authors as a Separate persona is not auto-announced until byline and
privacy identity can be represented independently without exposing the owner.

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
bold label and a plain line, rendered owner-only. `<HelpHint>` generalises it.
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

⚠️ **Write this copy pronoun-free.** The wording below parameterises the persona
name; it must not also assert a pronoun. An earlier draft read "…can see *she's*
yours", which shipped and rendered as "People visiting **Marcus** can see
**she's** yours" — and worse, "People visiting **this persona** can see she's
yours" before a name is typed. Personas are the identity feature and carry their
own `gender`; asserting she/her over a user's chosen identity is the wrong
default. See #132.

> **Linked** — People visiting {name} can see this persona is yours, and anyone
> who follows you will also see {name}'s followers-only posts.
>
> **Separate** — Nobody can tell {name} is yours. {name} builds a following from
> scratch.

First-run persona explainer (the only persona copy a persona-free user sees).

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

## Shipped phases

Gates for every phase:
`pnpm run type-check && lint && test && build` and
`./vendor/bin/pint --test && composer test`.

**Inline help ships with the control it explains.** A phase that lands the
switcher without H1 has not landed.

### Phase 0 — Feed split + bio fields → issue #98 *(shipped)*

`/feed` restored as a real page; `/` and `/dashboard` repointed; `users.bio` and
`users.pronouns` added (fields only, no UI); the pinned tests updated. No schema
beyond the one migration, no privacy surface.

### Phase 1 — Make `/me` a profile *(shipped)*

The `feed` tab is gone; the owner defaults to a showcase with a "Latest" strip.
The identity strip is an avatar tab rail, **omitted with no personas**. The
profile surfaces `bio`/`pronouns` in the header and identity editor;
`<HelpHint>` is generalised from `PrivacyBadge`; `OnboardingChecklist` lives on
`/feed` with **no persona step**.

No schema, no gate changes.

### Phase 2 — Consolidate character visibility *(shipped)*

`FavoriteService` delegates character visibility to
`Character::isViewableBy()`. `StoreReportRequest` accepts persona and user
targets. This remains a pure consolidation: the shared privacy rule did not
change.

### Phase 3 — Personas as public profiles *(shipped)*

`characters.ulid` and `is_linked`;
`GET /c/{ulid}` using the `first()` + generic-404 pattern; persona mode in the
profile component; **byline inversion** including the audience-fail fallback;
Linked/Separate control + H2 copy; personas in Explore/People.

The visitor surface ships with the Phase 4/Track A scrubbing described in
[The Separate boundary](#b3).

### Phase 4 — Persona follows *(shipped; privacy-critical)*

`follow_requests.recipient_character_id` with `cascadeOnDelete` and NULL-safe
uniqueness; the persona-aware rule applied to **all nine call sites**; subsumption
inside `FollowGraph` only; follow lists rendered by public identity; notification
fan-out scoped by edge; the [Separate boundary](#b3) surface scrubbing.

Regression tests pin the negative assertions: a follower of the owner must
**not** see a Separate persona's followers-only content, must **not** receive its
notifications, and must **not** see the owner's posts from a persona-only edge.

### Phase 5 — Feed composition *(shipped)*

`scope=mixed|following` with Following as the default; auto-post at **approval**,
carrying the exact audience/allowlist; "Announce this" checkbox + H3 copy.
Story authorship uses `story_authors.character_id`; Separate-owner story
announcements remain suppressed as described above.

### Phase 6 — View as *(shipped)*

Public / Follower simulation running the real gate. ⚠️ **Cut "a specific person"
from v1** — it requires plumbing a viewer override through every payload builder
for marginal value over the two-tier simulation.

### Switcher *(shipped with its consumers)*

Navbar switcher + `POST /api/identity` + H1 copy, landed together with the
composer, upload dialog, and new-story path reading the session identity. Not
Phase 1.

## Risks, settled decisions and deferred questions

| Risk | Mitigation |
|---|---|
| **The persona-aware rule has two implementations** (boolean + subquery). Drift = a leak. | Keep it in `FollowGraph`; test both forms against identical fixtures including the Separate case |
| **A follow-graph call site or persona-reachable surface is missed** and silently widens access or exposes the owner | Keep the route inventory and cross-surface Separate-persona guard current; require an explicit reason wherever persona context is denied |
| **Persona session state leaking into privacy decisions** | Authorship only; assert the gate is invariant to `active_character_id` |
| **Persona pages multiply the moderation surface** | Admin views resolve `character.user_id` regardless of Linked/Separate; reports accept both user and persona targets |
| **Blocking (not yet built) collides with Separate personas** — if @alice blocks Kira and Ben's content vanishes, she infers the link. Blocking must be account-level to prevent evasion. | Decide deliberately when blocking is designed |
| **Mixed feed distributes reactively moderated posts** | Keep Following as the fail-closed default; Mixed remains explicit opt-in and still requires Approved posts from other users |

### Settled implementation decisions

1. **Persona interests.** An `interest_ratings.character_id` of null is the
   human profile's rating. Linked personas with `inherit_interests=true` read
   those rows; switching a Linked persona to explicit ratings copies the owner's
   current rows as an editable starting point. Separate personas are forced to
   `inherit_interests=false` and start with no rows, so they never expose the
   owner's interest fingerprint.
2. **Story authorship.** Identity is per co-author on nullable
   `story_authors.character_id`; ownership and privacy remain on the story's
   human account.
3. **Persona follow lifecycle.** Persona follows auto-accept immediately. They
   never enter the human friendship-request inbox and the API does not expose
   the underlying account edge mechanics.
4. **Announcement audience inheritance.** An announcement literally copies and
   propagates the attached item's audience, discoverability, and exact
   SpecificPeople allowlist. Manual posts compute the exact intersection of
   their requested policy and all attachments.
5. **Linked attribution.** Linked deliberately exposes its human owner as page
   meta. Separate never does. Personas appear in the People directory under
   these same rules.
6. **Persona discovery.** `discoverable` is an independently edited listing
   switch. Persona `preferred_user_types` / `preferred_genders` are unsupported;
   personas do not have a viewing context, and discovery matching remains based
   on the persona's independent interest ratings.

Blocking and chat remain deferred design work, as listed above; they are not
implicit gaps in the shipped persona contract.
