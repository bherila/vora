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
- **Profiles and personas**: users have self-profiles and optional Linked or
  Separate personas, with independent follow scopes and authoring identities.
- **Feed and discussions**: users publish posts, comment on discussions, and
  manage their contributions from their activity page.
- **Direct messages**: mutual account followers can chat, with unread counts
  and retained history when messaging becomes unavailable.
- **Blocking**: users can block accounts or personas, with privacy rules that
  preserve Separate personas' owner anonymity.
- **Media and stories**: users upload photos and videos, play adaptive-bitrate
  HLS video, and write long-form or branching stories. Media and stories pass
  through admin review. See [docs/media/overview.md](docs/media/overview.md).

## Tech Stack

- **Backend**: Laravel 13 on PHP `^8.4`
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

The **Two-account acceptance** GitHub Actions workflow runs on every pull request
and can also be started manually. It checks auth, persona switching, follow and
blocking privacy, discussions, story readers, media visibility/playback failures,
and chat, including desktop and
mobile Chromium journeys. See
[TESTING.AGENTS.md](TESTING.AGENTS.md) for its scope and browser smoke checks.

## Key Routes

- `/register`, `/login`, `/email/verify`, `/pending-approval`
- `/feed`, `/explore`, `/me`, `/me/activity`
- `/users`, `/users/follow-requests`, `/c/{ulid}`
- `/messages`, `/messages/{conversation}`
- `/m/{ulid}`, `/s/{ulid}`, `/p/{ulid}`
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

### Shared cPanel scheduler and queue

The production cPanel account must run Laravel's scheduler once per minute. Add
this cron entry in cPanel, replacing the account name and application directory
with the deployed values while keeping the production PHP binary explicit:

```cron
* * * * * /opt/cpanel/ea-php85/root/usr/bin/php /home/CPANEL_USER/laravel/artisan schedule:run >> /home/CPANEL_USER/laravel/storage/logs/scheduler.log 2>&1
```

The scheduler runs a short database worker for `chat-notifications,default` in
that priority order. It exits when empty or after about 50 seconds; no Redis,
Supervisor, Horizon, Reverb, or persistent daemon is required. Verify the cron
is active after every deployment (and after cPanel/PHP changes) with:

```bash
/opt/cpanel/ea-php85/root/usr/bin/php /home/CPANEL_USER/laravel/artisan ops:queue-health
```

That diagnostic reports scheduler-heartbeat freshness, the age and count of
pending jobs in both queues, and failed-job history. `--json` is available for
monitoring. It exits nonzero when the heartbeat is older than three minutes, a
queued job is older than five minutes, or any failed job exists. The deployment
workflow runs this check after each release; operators can also run it manually
when diagnosing the cPanel scheduler or database queues.

## License

Private - All rights reserved
