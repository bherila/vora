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

- **Stack**: Laravel 12 on PHP ^8.2, React 19 + TypeScript, Vite, Tailwind CSS v4.
- **UI primitives**: shadcn-style local components built on Base UI, not Radix UI.
- **Package manager**: pnpm — never use npm or npx directly.
- **Database**: SQLite only in development and tests (in-memory for tests).
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
4. Tests must use SQLite in-memory. Do not configure tests to use any other driver.

## Laravel conventions

- Typed return types on all methods.
- Use Form Requests for validation; do not inline `$request->validate()` in controllers.
- Eager-load relationships; avoid N+1 queries.
- Follow PSR-4 autoloading; keep class files in the directory matching their namespace.

## React / TypeScript conventions

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
