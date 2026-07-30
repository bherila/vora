# AGENTS.md

For AI coding agents working in this Laravel + React/TypeScript/Vite starter template.

## Operating principle

Make the smallest coherent change that solves the problem. Include adjacent low-risk fixes only when directly relevant. Inspect related files before editing, parallelize independent reads and checks, and run targeted validation during iteration. Avoid unrelated refactors, dependency changes, broad formatting churn, and production builds unless the task requires them.

Treat user requests as the minimum bar, not a ceiling: finish the adjacent checks
and low-risk cleanup needed for the result to hold up in review.

If `scripts/wt/{create,bootstrap,remove}.sh` exist, use them instead of raw
`git worktree add`, `composer install`, or `pnpm install` for fresh worktrees.
Never run `npm`, `npm ci`, or `npx` in this repo.

## Project shape

- **Stack**: Laravel 13 on PHP ^8.3, React 19 + TypeScript, Vite, Tailwind CSS v4.
- **UI primitives**: shadcn-style local components built on Base UI, not Radix UI.
- **Package manager**: pnpm — never use npm or npx directly.
- **Database**: SQLite in development and by default in tests (in-memory);
  CI also gates backend changes against an isolated MySQL 8.0 service container.
- **Dependency management**: Composer (PHP) + pnpm (JS). Do not mix.
- **Docs**: README.md is the human-facing project overview; AGENTS.md is the
  agent contract; TESTING.AGENTS.md owns validation details; CLAUDE.md should
  only point to those files and avoid duplicating their content.

## Commands

```bash
# Setup
composer install && pnpm install
cp .env.example .env && php artisan key:generate
# Do not run migrations unless explicitly requested; see Database Safety.

# Development
composer dev
# Or run services separately:
php artisan serve
pnpm run dev

# Frontend checks
pnpm run type-check
pnpm run lint
pnpm run test
pnpm run build

# Backend checks
./vendor/bin/pint --test
composer test
```

## Database safety

1. Never run `php artisan migrate` or `php artisan schema:dump` unless the user explicitly requests it.
2. When explicitly requested, use SQLite only: `php artisan migrate --database=sqlite --no-interaction`.
3. For schema dumps: `php artisan schema:dump --database=sqlite` — never use `--prune`.
4. Local and developer test runs must use SQLite in-memory.
5. The only MySQL test target is the isolated service container in the CI
   `mysql` job. It must use the dedicated `vora_ci` database and credentials
   defined in the workflow and must never point at a shared or production
   database.

## Laravel conventions

- Typed return types on all methods.
- Use Form Requests for validation; do not inline `$request->validate()` in controllers.
- Eager-load relationships; avoid N+1 queries.
- Follow PSR-4 autoloading; keep class files in the directory matching their namespace.

## React / TypeScript conventions

- Use the shared page-width tokens in `resources/js/components/page-width.ts`.
  Reading and form surfaces use `READING_PAGE_WIDTH` (`max-w-3xl`) to protect
  line length; browsing and grid surfaces use `BROWSING_PAGE_WIDTH`
  (`max-w-7xl`) so cards, galleries, and optional secondary rails can use the
  viewport. `/me`, Explore, media galleries, and People are browsing surfaces;
  settings, story readers, and long prose are reading/form surfaces.

- Use `interface` for component props.
- Named function declarations for components (not arrow-function const exports).
- Strict TypeScript — no `any` unless unavoidable and commented.
- Import aliases via `@/` where configured in `tsconfig.json`.
- Use existing utility and component abstractions before creating new ones.
- When a Blade view uses a new `@vite(...)` entrypoint, add that entrypoint to
  `vite.config.ts` so production builds and CI include it.
- Blade-provided frontend bootstrap data should use
  `<script type="application/json" @cspNonce>` with `@json(...)`, then parse
  `textContent` in TypeScript. Do not inline executable data scripts without
  the CSP nonce.
- Keep CSP in mind: avoid inline `style` attributes in React pages unless the
  policy is intentionally updated.

## Context budget

Keep the active context focused on the files relevant to the change. Do not read the full documentation tree or load every config file on every task. Load specific controllers, services, tests, and config for the touched paths only.
