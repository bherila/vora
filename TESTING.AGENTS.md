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
runs the backend gate against MySQL 8.0 and MariaDB 10.11 service containers in
the `sql` matrix defined in `.github/workflows/ci.yml`; those jobs are
intentionally additional coverage, not a replacement for SQLite.

## Database safety

Never run migrations or schema dumps unless the user explicitly requests it. When explicitly requested:

```bash
php artisan migrate --database=sqlite --no-interaction
php artisan schema:dump --database=sqlite
```

Never use `--prune`. Tests must use SQLite in-memory and must never run against a production or shared database.
The sole exceptions are the CI-only MySQL and MariaDB jobs, which are guarded
to accept only their loopback service containers, dedicated `vora_ci` database,
matching engine marker, and dedicated CI credentials. Never reuse those job
configurations to target a shared or production database.
