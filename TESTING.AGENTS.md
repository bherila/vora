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

Run the **Two-account acceptance** workflow manually against the branch under
review. It checks password/two-factor auth and approval gates, authoring identity
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

These are Laravel HTTP feature tests and React component tests. They do not
exercise a live browser's navigation, focus, layout, or cookie handling.

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
