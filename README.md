# Vora

A Laravel + React application with approval-gated accounts, admin-managed interest
taxonomy, and user interest ratings.

## Features

- **Approval-gated registration**: users register, verify their email, then wait
  for admin approval before accessing the app.
- **Account settings**: users can update editable account fields, change
  password, and manage passkeys. Admins can lock name/email edits and manually
  record ID verification.
- **Admin users**: admins can view users, approve verified users, toggle admin
  and disabled flags, and manage lock/verification fields.
- **Interests**: admins define a hierarchical interest catalog. Users browse the
  hierarchy and rate each predefined interest from `-10` to `+10`.
- **Interest requests**: users can request new interests for admin review.
  Admins can edit, approve, reject, or delete pending requests.
- **Audit log**: auth audit data is available through the admin UI.
- **Video media pipeline (infrastructure ready)**: Cloudflare R2 buckets and an
  out-of-band HLS transcoder are provisioned for adaptive-bitrate video. The
  app-side upload/playback feature is not built yet. See
  [docs/s3-hls-integration.md](docs/s3-hls-integration.md).

## Tech Stack

- **Backend**: Laravel 13 on PHP `^8.3`
- **Frontend**: React 19 + TypeScript
- **UI**: shadcn-style components using Base UI primitives
- **Styling**: Tailwind CSS v4
- **Build**: Vite
- **Package managers**: Composer and pnpm
- **Database**: configured by `.env`; tests always use SQLite in-memory

## Getting Started

```bash
composer install
pnpm install
cp .env.example .env
php artisan key:generate
```

Configure `.env`, then run migrations when you intend to update that database:

```bash
php artisan migrate
```

Start development services:

```bash
composer dev
```

Or run them separately:

```bash
php artisan serve
pnpm run dev
```

## Validation

Frontend:

```bash
pnpm run type-check
pnpm run lint
pnpm run test
pnpm run build
```

Backend:

```bash
./vendor/bin/pint --test
composer test
```

Tests are configured to use SQLite in-memory regardless of local `.env`
database credentials. This is enforced by `phpunit.xml` and `Tests\SafeTestCase`.

## Key Routes

- `/register`, `/login`, `/email/verify`, `/pending-approval`
- `/dashboard`
- `/user/settings`
- `/interests`
- `/admin/users`
- `/admin/interests`
- `/admin/audit-log`

## Deployment

The GitHub Actions deployment workflow installs Composer and pnpm dependencies,
builds Vite assets, syncs the Laravel app to the server, and runs:

```bash
php artisan migrate --force --no-interaction
php artisan config:clear
php artisan config:cache
```

See `.github/workflows/deploy.yml` for the current production deployment flow.

## License

Private - All rights reserved
