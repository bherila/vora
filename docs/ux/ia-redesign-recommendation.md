# Vora UX / IA Redesign Recommendation

> Design study output. Not yet implemented — decisions for the product owner.

## TL;DR

**New top-level nav (logged-in):** `Feed · Explore · People · Create · Requests(badge)` + avatar menu. Home is dropped for authenticated users. **Five items, down from nine.**

**The biggest moves:**
1. **Land logged-in users on Feed.** `StaticPageController@home` redirects authenticated+approved users to `route('feed')`; guests/crawlers still get the CMS marketing page. Drop the always-on "Home" nav item.
2. **Kill the Dashboard.** Its 8-card grid only duplicates the nav. `301`-redirect `/dashboard → /feed`. Promote the user's identity to a real self-profile at `/me` (avatar menu), reusing `follow-profile.tsx` in owner mode with inline edit.
3. **Merge Media + Characters + Stories into one "Create" item** with real server-rendered sub-routes (`/create/media`, `/create/characters`, `/create/stories`), each mounting its existing React entrypoint. Keep old URLs as redirects.
4. **Edit-in-context rule:** Characters and single/bulk Media editing become Base UI **Dialogs**; Stories stay a **dedicated full-page editor** (now with its own URL `/create/stories/{id}/edit`) for the long-form + CYOA canvas.
5. **Explore filtering** defaults to the viewer's own profile interests, shows a hierarchical interest selector, and lets the user deviate as an explicit, reversible exception.

## Current problems (grounded in the map)

| # | Problem | Evidence |
|---|---------|----------|
| 1 | 9 nav items; the bar is unscannable, worse on the mobile drawer | `app.blade.php:31–41` builds 9 `$__navItems` |
| 2 | Dashboard is pure bloat — 8 link cards that duplicate the nav | `routes/web.php:148` → `dashboard.blade.php` |
| 3 | Logged-in users land on a marketing splash | `routes/web.php:38` `/ → StaticPageController@home`, shown to authed users |
| 4 | No self-profile — you can't see yourself as others do; identity edits live only in a form | Settings (`user-settings.tsx`) is form-only; no `/me` |
| 5 | Characters wastes half the screen on an always-visible companion form with no clear edit affordance | `characters.tsx:255` two-column layout, form at 296–345 |
| 6 | No inline edit of a single media item after upload (typos unfixable); bulk edit is a cramped 3-col sidebar | `media.tsx:359–370` delete-only; `media.tsx:590–646` sidebar |
| 7 | Stories editor swaps the page in-place — no deep link, broken browser back | `stories/page.tsx:108–125` |
| 8 | Requests badge vs NotificationBell split inbox — partial overlap of follow/co-author events | badge `/users/follow-requests`; bell shows same events |
| 9 | "Users" reads like an admin term in a social product | `app.blade.php:39` |
| 10 | Explore interest filter starts empty and renders a **flat** list, ignoring the existing hierarchy helper | `explore.tsx:27` `interestIds=[]`; `interest-picker.tsx` flat list; `interests/tree.ts` helper unused here |

## Recommended information architecture

Top-level nav, in order (authenticated):

| Item | Route | What lives here |
|------|-------|-----------------|
| **Feed** | `/feed` (default landing) | Social timeline; composer + infinite scroll. Unchanged. |
| **Explore** | `/explore` | Cross-user discovery (Media/Stories tabs). Gains interest-aware defaults + hierarchical filter + infinite scroll + creator attribution. |
| **People** | `/users` (label "People") | User directory + individual profiles. Label-only rename. |
| **Create** | `/create/*` | Owner CRUD: **My Media · Characters · Stories** as sub-route tabs. |
| **Requests** (badge) | `/users/follow-requests` | Inbound follow + co-author invites. Keeps the only badge — earns its slot. |

To the right (unchanged structure): NotificationBell (activity only), theme toggle, **avatar menu** → `View profile (/me)` · `Account settings (/user/settings)` · `Invites` · `Log out`. Admin dropdown unchanged.

Detail/share routes stay standalone and are **not** nav items: `/m/{ulid}`, `/s/{ulid}`, `/p/{ulid}`, `/users/{user}`.

## Answers to the specific questions

### Q1 — Consolidation (Dashboard / Feed / Media / Stories / Explore)

| Surface | Verdict | Why |
|---------|---------|-----|
| **Dashboard** | **Delete.** `/dashboard → /feed` redirect. | Only an 8-card link grid duplicating the nav. Zero unique value. |
| **Feed** | **Keep top-level; make it the landing.** | Highest-frequency surface, recurring engagement. |
| **Explore** | **Keep top-level, separate from Feed.** | Different mental model (everyone vs. people you follow). Merging buries your own audience behind strangers. |
| **Media + Characters + Stories** | **Merge into one "Create" item** with sub-route tabs. | All three are owner-authored content sharing `AudienceField`, the interest picker, and the Base UI Dialog pattern. Three nav slots → one. |

**Do not** merge Feed↔Explore, and do not over-collapse into a 4-item bar that hides Requests in a tab — the at-a-glance badge count must survive.

### Q2 — Home page for logged-in users

**Yes — hide Home from the authed nav and stop landing logged-in users on the CMS splash.**

- In `StaticPageController@home`: `if (auth()->check() && $user->approved) return redirect()->route('feed');` else render the CMS page (guests + SEO unchanged).
- Remove the unconditional `'Home'` entry at `app.blade.php:32`.
- Brand logo (`brand` payload, links to `route('home')`) is left pointing at `/`, which now resolves to Feed for authed users and the splash for guests — so the logo always means "home" contextually.
- **Land on Feed, not /me.** This is a social product; Feed is the recurring-value surface. The profile is one click away on the avatar.

### Q3 — Own profile + inline edit (and the fate of Settings)

**Promote the profile — but via a new `/me`, not by reviving the Dashboard.**

- Add `Route::get('/me')->name('me')` → `FollowController` returning the same `profilePayload()` it builds for `/users/{user}`, with `isSelf=true` and never `restricted`. Reuse `follow-profile.tsx` in an owner mode.
- When `isSelf`, each region (name/bio, avatar, gender/user-type, interests, audience) gets an inline **Edit** affordance opening a Base UI Dialog, hitting the **existing** endpoints — `PATCH /api/account`, `/api/account/profile-picture`, interest ratings — so no parallel form/validation.
- **Add a "View as visitor / restricted" toggle** on `/me` (cheap — the component already branches on viewer) to fix the audience-asymmetry pain.

**Settings is split, not deleted:**

| `/me` (public identity, inline edit) | `/user/settings` (account & security) |
|---|---|
| display name, avatar, gender/user type, interests, profile audience | legal name, email, notifications, push, passkey/password, data export, deactivate/delete |

Add an explicit pointer from Settings → "View / edit your profile" so users who learned "everything is in Settings" aren't stranded.

### Q4 — Character / Media editing: page vs modal

**Rule of thumb:** *Flat, single-object form that fits in ~600px → Base UI Dialog. Multi-mode / long-form / canvas editor → dedicated full page.*

| Surface | Decision | Notes |
|---------|----------|-------|
| **Characters** | **Base UI Dialog.** | Replace the always-visible two-column form (`characters.tsx:255`) with a card grid; "Add" and per-card "Edit" open the same Dialog (pattern already in `media.tsx:464`). Profile-picture upload moves *inside* the dialog. Add **"Save & add another"**. Add unsaved-changes guard on close. |
| **Media (single item)** | **Base UI Dialog.** | New per-item "Edit" for title/audience/character/discoverable, reusing the upload modal's `AudienceField`/interest picker (`media.tsx:523–547`). Fixes "no edit after upload." |
| **Media (bulk)** | **Base UI Dialog.** | Move the cramped inline sidebar (`media.tsx:590–646`) into a Dialog from the selection toolbar — consistent single + multi edit. |
| **Stories** | **Dedicated full page.** | Long-form body + `CyoaGraphEditor` canvas are too big for a modal. Give it its own URL `/create/stories/{id}/edit` instead of the in-place swap (`stories/page.tsx:108–125`) so back/forward + deep links work. |

### Q5 — Overall tidy-up

- 9 → 5 nav items + avatar; clean "where am I" story without burying the badge.
- One coherent "manage my stuff" model (Create) replacing three.
- One coherent "who I am" model (`/me` + avatar menu) replacing the scattered Dashboard/Settings split.
- Reconcile Requests vs NotificationBell: **bell = activity feed only; Requests badge = inbound follow + co-author requests.**
- Consistent paging: Explore gets Feed's `IntersectionObserver` infinite scroll.

## Explore filtering & interest selection (follow-up requirements)

All three asks are well-supported by existing code — the data and helpers are already there; they're just not wired into the Explore filters.

### F1 — Default the interest filter to the viewer's own profile interests

- `/api/interests` already returns every interest with a `rating` field (the viewer's `InterestRating` level, `null` when unrated). The user's "profile interests" = interests where `rating !== null`.
- Today Explore (`explore.tsx:27`) initializes `interestIds = []` (no filter). Seed it instead from the rated interests on first load.
- Cleanest source: have `ExploreController@page` hydrate a `defaultInterestIds` array into `initialData.explore` (it already has the user; pluck `interest_id` from `InterestRating` where `user_id = viewer`), so the page is interest-relevant on first paint with no extra client round-trip.
- Stories tab shares the same `interestIds` state, so it inherits the default automatically.

### F2 — Let the user deviate from their profile interests (as an exception)

- The filter is already a free multi-select, so add/remove works. What's missing is the *framing* so the default is visible and reversible:
  - Show a subtle line above the picker: **"Showing media for your interests"** with a **"Reset to my interests"** action (re-applies `defaultInterestIds`) and a **"Clear"** action (empties the filter to browse everything).
  - Track whether the current selection equals the profile default to toggle that label between "your interests" and "custom filter."
- This makes deviation an explicit, one-click-reversible exception rather than silently overriding an invisible default.

### F3 — Hierarchical interest selector (not a flat list)

- The hierarchy infrastructure already exists and is used by the admin view, profile editor, and character editor: `resources/js/interests/tree.ts` (`buildInterestTree`, `getDepthPaddingClass`, `flattenInterestTree`, `collectDescendantIds`). The data carries `parent_interest_id`.
- The shared **`InterestPicker`** (`resources/js/components/interest-picker.tsx`) — used by both Explore filters and the media uploader — renders a **flat alphabetical list** and ignores that helper. This is the gap.
- Fix: render the picker from `buildInterestTree`, indent rows by `getDepthPaddingClass(depth)`, keep the text-filter (when filtering, fall back to a flat match list, or keep matched nodes' ancestors for context).
- Optionally let selecting a parent imply its descendants via `collectDescendantIds` (matches how the character editor reasons about inheritance) — confirm desired filter semantics with the product owner before adding.
- Because the picker is shared, upgrading it improves the media uploader's tagging UX for free.

## Phased implementation plan

### Phase 1 — Nav + routing quick wins (low risk, high value)
| Step | Files / routes | Notes |
|------|----------------|-------|
| Land authed users on Feed | `StaticPageController@home` | `auth()->check() && approved → redirect()->route('feed')`. Keep CMS render for guests. |
| Drop "Home" from authed nav | `app.blade.php:32` | Brand logo still links `/`. |
| Delete Dashboard | `routes/web.php:148`, remove `dashboard.blade.php` | Replace route with `301 redirect('/feed')`. Audit `OnboardingChecklist` for `/dashboard` links. |
| Rename Users → People | `app.blade.php:39` label only | Route/API unchanged. |
| Reduce `$__navItems` to 5 | `app.blade.php:31–41` | Feed, Explore, People, Create, Requests(badge). |
| Update tests | `tests/Feature/NavbarHydrationTest.php` | Assert new 5-item set; assert `/` redirect for authed users. |

**CSP:** no change — payload stays in the existing `<script id="initial-data" @cspNonce>`.

### Phase 2 — Self-profile at `/me` + Settings split
| Step | Files / routes | Notes |
|------|----------------|-------|
| Add `/me` route | `routes/web.php`, `FollowController.php` | New `me()` reusing `profilePayload()` with `isSelf=true`. |
| **Eager-load `mutual_interests`** | `FollowController.php:142–150` | **Hard requirement** — fix the N+1 before `/me` is a high-traffic avatar destination. |
| Owner mode in profile view | `resources/js/user/follow-profile.tsx` | `isSelf` branch: inline Edit dialogs + "View as visitor" toggle. |
| Inline edit dialogs | reuse `PATCH /api/account`, `/api/account/profile-picture`, interest ratings | No new endpoint/validation. |
| Avatar menu | `app.blade.php:57–65`, `navbar.tsx` | Add `View profile → /me`; relabel Settings → `Account settings`. |
| Settings split | `resources/js/auth/user-settings.tsx` | Remove identity fields; add pointer to `/me`. Keep account/security only. |

### Phase 3 — Create (Studio) consolidation
| Step | Files / routes | Notes |
|------|----------------|-------|
| Add `/create/*` shell | new Blade shell + tab strip; `routes/web.php` | Real **server-rendered sub-routes**, each mounting its existing entrypoint. Not an SPA shell. |
| Mount existing entrypoints | `media.tsx`, `characters.tsx`, `stories/page.tsx` | Page bodies unchanged; only the wrapper. |
| Register in vite | `vite.config.ts` | Add the shell entrypoint; existing entries stay. |
| Redirects for old URLs | `routes/web.php` | `/media → /create/media`, etc. Protects bookmarks + tests. |

### Phase 4 — Editing dialogs
| Step | Files | Notes |
|------|-------|-------|
| Characters → Dialog | `characters.tsx` | Card grid + Add/Edit Dialog; avatar upload inside; "Save & add another"; unsaved-changes guard. Largest single change. |
| Media single-item Dialog | `media.tsx` | Reuse upload modal's `AudienceField`/interest picker. |
| Media bulk → Dialog | `media.tsx:590–646` | Move sidebar into Dialog from selection toolbar. |
| Stories own URL | `routes/web.php`, `stories/page.tsx` | `/create/stories/{id}/edit`; keep full-page `StoryEditorPanel`. |

### Phase 5 — Explore filtering + polish
| Step | Files | Notes |
|------|-------|-------|
| Hierarchical `InterestPicker` | `components/interest-picker.tsx` (+ `interests/tree.ts`) | Tree render; benefits media uploader too. |
| Default-to-profile-interests | `ExploreController.php`, `explore.tsx` | Hydrate `defaultInterestIds`; seed `interestIds`. |
| Reset / clear affordances | `explore.tsx`, `MediaFilters.tsx` | "Showing your interests · Reset · Clear". |
| Explore infinite scroll + creator attribution | `explore.tsx`, `useMediaListing`, `MediaGrid` | Match Feed's `IntersectionObserver`. |
| Reconcile bell vs Requests badge | `follow-requests.tsx`, NotificationBell | Bell = activity only; optional inbox sectioning. |

**Gates each phase:** frontend — `pnpm run type-check && lint && test && build`; backend — `./vendor/bin/pint --test && composer test`.

## Risks & open questions

| Risk | Mitigation |
|------|------------|
| Redirecting `/` for authed users breaks bookmarks/tests expecting the CMS home | Keep splash reachable via brand logo + footer; update tests; confirm no existing-user announcements live only on `/`. |
| Deleting `/dashboard` breaks bookmarks, `OnboardingChecklist` links, inbound notifications | `301 /dashboard → /feed`; grep for `route('dashboard')` and `/dashboard` references before removal. |
| `follow-profile.tsx` owner mode could leak edit controls or mis-render the privacy gate | Clean `isSelf` branch; never set `restricted` for self; test viewer vs owner rendering. |
| `profilePayload()` mutual-interests N+1 (`FollowController.php:142–150`) hit on every `/me` open | Eager-load **before** Phase 2 ships — gating requirement. |
| Characters Dialog rewrite is non-trivial (nested interests + avatar upload) | Scope as its own phase; add unsaved-changes guard the current form lacks. |
| Defaulting Explore to profile interests could show an *empty* Explore to users with no rated interests | Fall back to "no filter" (show everything) when `defaultInterestIds` is empty; never present a blank Explore. |
| Hierarchical parent-implies-descendants could change filter result counts unexpectedly | Confirm semantics with product owner; default to literal selection unless inheritance is explicitly wanted. |

**Open questions for the product owner:**
1. Landing page: confirm **Feed** (recommended) vs `/me`.
2. Should the `/me` "View as visitor" toggle ship in Phase 2 or as a fast-follow?
3. Label the consolidated owner-content item **"Create"**, **"Studio"**, or **"My Content"**?
4. Explore interest filter: literal selection only, or should selecting a parent interest auto-include its descendants?
