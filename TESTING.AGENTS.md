# Testing — Agent Requirements

Routine validation checks for AI coding agents in this template.

## Frontend gate

Run before committing frontend changes (TypeScript, React, CSS, Vite config):

```bash
pnpm run type-check
pnpm run lint
pnpm run test
pnpm run build
```

All four must pass. Fix type errors and lint violations before pushing — do not suppress them without justification.

## Backend gate

Run before committing PHP changes:

```bash
./vendor/bin/pint --test
composer test
```

`pint --test` checks formatting without writing; fix violations by running `./vendor/bin/pint` (without `--test`).

During iteration, targeted tests are acceptable:

```bash
php artisan test tests/Feature/SomeFeatureTest.php
php artisan test --filter="some_specific_test"
```

Before finalizing, broaden to the full backend gate.

SQLite in-memory is the default for every local and developer test run. CI also
runs the backend gate against a MariaDB 10.6 service container in the `sql` job
defined in `.github/workflows/ci.yml`; that job matches production and is
additional coverage, not a replacement for SQLite.

That job runs `composer test:parallel`, so Laravel gives each PHPUnit process
its own `vora_ci_test_<n>` database. Nothing changes locally: the parallel
plumbing is skipped entirely for in-memory SQLite
(`TestDatabases::whenNotUsingInMemoryDatabase`), and `composer test` remains the
sequential command the backend gate above asks for.

## Two-account acceptance

The **Two-account acceptance** workflow runs on pull requests and can also be
run manually against the branch under review. It checks password/two-factor
auth and approval gates, authoring identity
switching, account and persona follows, Separate-persona privacy, blocking,
discussion contribution ownership, and chat eligibility/history. Its Jest step
checks navbar identities, profiles, blocking, chat, discussions, and activity.

The workflow's backend step can also be run locally:

```bash
php artisan test \
  tests/Feature/Auth/AuthFlowTest.php \
  tests/Feature/IdentitySessionTest.php \
  tests/Feature/Follow/FollowRequestTest.php \
  tests/Feature/Privacy/PersonaFollowPrivacyTest.php \
  tests/Feature/Privacy/SeparatePersonaSurfaceGuardTest.php \
  tests/Feature/Privacy/BlockingPrivacyTest.php \
  tests/Feature/Acceptance/TwoAccountDiscussionTest.php \
  tests/Feature/Chat/ChatApiTest.php
```

The workflow also runs Playwright journeys in desktop Chromium and mobile
Chromium emulating a Pixel 7. These use two independent browser sessions for
registration, email verification and admin approval, password/two-factor login,
Linked/Separate persona creation and authoring, persona follow privacy, protected
media rendering and access revocation, and private discussion contribution
ownership after follow access is removed. Communication journeys cover mutual
follows, unread counts, chat send/reply/reload, keyboard focus, browser
Back/Forward, blocking, unblocking, and logout. Profile actions update on block
and unblock without a reload. Blocked threads disappear; unblocking restores
retained history without restoring removed follows or allowing new messages.

Run the browser journeys locally after installing the frozen Composer and pnpm
dependencies and stopping any Vite dev server. Run these separately from the
backend suite, which changes shared view/cache files during tests:

```bash
pnpm exec playwright install chromium
pnpm run test:browser
```

The command builds production assets and starts a test-only loopback HTTP bridge
on `127.0.0.1:4187`. The bridge forwards requests into one long-lived PHPUnit
worker, retaining SQLite `:memory:` across requests. Database sessions, auth,
CSRF protection, Laravel routes, and frontend requests run normally. The worker
creates approved accounts and exposes their actual two-factor codes only through
test fixture endpoints. The registration journey creates its account through the
UI, follows the signed verification link from a captured notification, and uses
an independent admin session for approval. Media fixtures use a tiny image in
fake object storage, served through real protected asset routes and policies.
The bridge captures streamed image responses as well as normal HTML/JSON.
No test endpoints are registered in the application.
Keep one worker (`workers: 1`) and do not point this harness at another database.

Playwright saves failure traces and screenshots in `test-results/`, also uploaded
by CI. These journeys do not cover real email delivery, object-store uploads,
video transcoding/playback, or a deployed PHP web server. Keep the feature tests
and manual checks below for those integrations and additional audience cases.

### Browser smoke checks

Use two separate browser profiles with approved, non-admin test accounts on a
development or staging instance. Use an admin session only for approval steps.
Record the tested commit, browser, viewport, and results. Repeat the navigation
and chat checks at a narrow mobile viewport.

1. **Auth and approval:** register a test account, verify its email, and confirm
   it cannot access Feed before admin approval. Complete password/two-factor
   login after approval; confirm Feed opens. Log out and confirm protected
   pages require login again.
2. **Personas:** create Linked and Separate personas. Switch authoring identity
   and publish a post; confirm its byline and the switcher's help text. As the
   second account, confirm the Separate persona's profile, posts, and follow
   lists do not expose its owner. Switching identity must not change what the
   first account can view.
3. **Follows:** follow the first account from the second, accept the request,
   and confirm Followers content appears. Follow a persona separately and
   confirm that edge does not grant access to the owner's account-only content.
4. **Discussions:** add comments and replies from both accounts. Remove the
   second account's follow access; confirm the private discussion disappears,
   while that account can still remove its own contributions from My Activity.
   A deleted parent must not remove the other account's reply.
5. **Chat:** establish mutual account follows and open Messages. Send and reply,
   confirm unread counts clear on opening the thread, and reload its URL to
   check history. Test keyboard focus and browser Back. Remove a required follow;
   confirm composing becomes unavailable while existing history remains.
6. **Blocking:** restore mutual follows, then block the other account. Confirm
   chat cannot send and blocked content disappears. Unblock from Settings;
   confirm removed follows are not silently restored. Repeat with a Separate
   persona and check that the blocked list names the persona without its owner.
7. **Media integrations:** upload a photo and video through the profile. Confirm
   review state, photo rendering, and HLS playback after processing. As the second
   account, check allowed access, then revoke access and retry the direct media
   and asset URLs. Check a Separate persona's media never names its owner.

## Database safety

Never run migrations or schema dumps unless the user explicitly requests it. When explicitly requested:

```bash
php artisan migrate --database=sqlite --no-interaction
php artisan schema:dump --database=sqlite
```

Never use `--prune`. Tests must use SQLite in-memory and must never run against a production or shared database.
The sole exception is the CI-only MariaDB job, which is guarded to accept only
its loopback service container, engine marker, dedicated CI credentials, and
the `vora_ci` database or one of the `vora_ci_test_<n>` databases Laravel
derives from it under `--parallel`. That name allowlist is deliberately narrow
-- digits only, anchored at both ends -- and is pinned by
`tests/Unit/DatabaseSafetyGuardTest.php`. Never reuse that job configuration to
target a shared or production database.
