# Vora

A Laravel + React application with approval-gated accounts, admin-managed interest
taxonomy, and user interest ratings.

## Features

- **Approval-gated registration**: users register, verify their email, then wait
  for admin approval before accessing the app.
- **Account settings**: users can update editable account fields, change
  password, and manage passkeys. Admins can lock name/email edits and manually
  record ID verification.
- **Local or provider sign-in**: each installation either checks passwords,
  email codes and passkeys itself, or sends people to an OAuth identity provider
  (see [Sign-in through an identity provider](#sign-in-through-an-identity-provider)).
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
- **Private Library**: selectively enabled cloud storage for uploaded or saved
  media and notes, organized into private collections with optional links to
  existing personas. Users explicitly share items or collections with named
  recipients. Items saved from someone else stay private to the saver, including
  in shared collections. Saved files survive the original uploader's account deletion
  through independent references to the same stored file. Original-file usage
  is visible in Library and Settings; admins enable storage and set optional
  account limits. Pending uploads reserve space, and users see allowance warnings.
  Bulk actions organize or remove selected items without copying stored files.

## Tech Stack

- **Backend**: Laravel 13 on PHP `^8.5`
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

For isolated local development and media integration checks, use the reusable
[Docker staging setup](docker/local/README.md). It includes the normal app,
private S3-compatible storage, real FFmpeg HLS, captured email, and disposable
accounts. Initialization is explicit; stopping the services preserves their data.

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

- `/register`, `/login`, `/email/verify`, `/pending-approval` (`/oauth/callback`
  under provider sign-in, which turns `/register` off)
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
php artisan config:clear
php artisan config:cache
php artisan ops:instance-profile --expect=vora
php artisan migrate --force --no-interaction
php artisan schema:assert-persona
php artisan view:clear
```

In embedded auth mode, deployment also runs
`php artisan auth-manager:migrate --force --no-interaction` after vora's migrations.
The provider has its own prefixed tables and migration repository. Its signing keys
under `storage/` are initialized once and retained across deploys.

See `.github/workflows/deploy.yml` for the primary production trigger. The reusable
`release-instance.yml` workflow tests and builds once, then transfers that exact
release to a selected GitHub environment. See [Event instances](docs/event-instances.md)
for deployment profiles, isolated storage, and additional targets.

### Name and Home Screen icon

Each installation names itself with `APP_NAME` and sets its own icon in
**Admin → App icon & name** (or `php artisan ops:app-icon path/to/icon.png`). The icon
is stored under `storage/`, which deploys never overwrite, so no installation's
branding lives in this repository. `/manifest.webmanifest` serves the name and
icon to browsers that add the site to a Home Screen. A shorter **Home Screen
name** can be set on the same page for the label under the icon.

### Sign-in through an identity provider

For the control inventory, designated emergency administrator, delegated
administrator-role integration and rollout evidence, see
[provider account-management acceptance](docs/consumer-auth-acceptance.md).

vora has three auth modes: local (the default), external, and opt-in embedded;
see [docs/auth-modes.md](docs/auth-modes.md) for choosing and switching.

Set `AUTH_SIGN_IN=embedded` to run the packaged identity provider under `/auth` in
this installation. It owns separate credentials, tables and a session, and vora
still signs in through OAuth. Its released frontend assets are included in the
Composer package. Follow the [embedded setup](docs/auth-modes.md#embedded) to create
its schema and keys, provision identities and register vora's client before use.
Existing local or external installations keep their configured mode.

By default an installation signs people in itself. Set `AUTH_SIGN_IN=provider`
to send them to an OAuth identity provider instead, which then owns passwords,
passkeys, recovery and email addresses. Registration, invite links, the waitlist
and the local password and passkey pages are turned off; the provider admits
people by granting them this application, or by an invitation sent from here
(below). The first account to sign in becomes
an administrator, as with local registration. Accounts created at sign-in are
approved and email-verified, and enter their birth date once on the age page
when the site has a minimum age.

Accounts are linked by the provider's name (`OAUTH_PROVIDER`) and its opaque
subject, never by email. To keep an existing local account, link it before
switching, or the person's first sign-in is refused because the address is
taken:

```bash
php artisan oauth:bind-subject <account-id> 1234
```

Then set `OAUTH_PROVIDER`, `OAUTH_PROVIDER_URL`, `OAUTH_CLIENT_ID`,
`OAUTH_CLIENT_SECRET` and `OAUTH_REDIRECT_URI` (`https://<site>/oauth/callback`),
switch `AUTH_SIGN_IN=provider` and rebuild the config cache. Sessions that were
not made through the provider end at the next request. Once the provider's
identity-status endpoint answers for this client, set
`BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED=true` so a session ends within five
minutes of the person being disabled, deleted or ungranted at the provider;
administrators' changes are checked immediately. Sign-out also ends the
provider's session and returns to this site's home page, which the provider
must accept for the client (a provider that matches it against the origin of
the registered redirect URI needs nothing more).

**Invitations.** Administrators, and anyone holding invites an administrator
issued, can invite people by email from **Invites** (`/invitations`). The
provider sends the email and admits the person to this site's client; each
invitation from a non-administrator spends one invite. The page also shows the
acceptance link once, for sharing directly; it is never stored here. To enable
it, generate an RSA key pair (at least 2048 bits) used for nothing else, keep
the private key outside the web root, and set
`BHERILA_AUTH_INVITATION_KEY_ID` and `BHERILA_AUTH_INVITATION_PRIVATE_KEY_PATH`.
The provider's operator then pins the public key under that key id, enables
invitations for this application and allows the client credentials grant on its
client.

**Deletions.** Every five minutes the scheduler reads the provider's feed of
people it deleted (`bherila-auth:consume-identity-tombstones`) and deletes each
one's account here as if they had deleted it themselves: soft-deleted, so
administrators can still restore or purge it. An account under a legal hold,
and the primary administrator, are left for an administrator; the command
exits non-zero and retries them on every run until then.

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

### City list for locations

Profile locations are city-level and optional. People can search for a city or
use their browser's location, which is matched to the nearest city on the server;
the coordinates are never stored. City data comes from a local copy of
[GeoNames](https://www.geonames.org/) (CC BY 4.0), so no paid or external lookup
API is used. The data is not shipped with releases: load or refresh it at any
time from **Admin → City list**, or with:

```bash
php artisan places:import
```

Imports download `cities5000` (places with at least 5,000 people) plus the
country and region lists. Admins choose another set (`cities500`, `cities1000`
or `cities15000`) on the City list page; `--dataset` or `PLACES_DATASET`
work too. Admins can also upload the three GeoNames files instead of
downloading them, preview an import before applying it, and turn on a monthly
refresh. Imports queued from the page run in the background from the
scheduler within a minute, so the cron entry below must be active.

Two modes:

- **Update** (default) adds and refreshes cities and removes cities the new
  data dropped, unless an account uses them. Those stay on their owners'
  profiles but leave search.
- **Replace** also removes regions and countries no city uses and, when
  asked, moves people off dropped cities to the nearest remaining one.

Re-running only applies changes, in multi-row statements. Nothing is pruned
when fewer than `PLACES_MINIMUM_ROWS` (default 1,000) cities were read, so a
truncated file cannot empty the list. On the command line, `--mode=replace`,
`--rehome`, `--dry-run`, `--no-prune`, and `--cities`/`--admin1`/`--countries`
(local files) mirror the page's options.

## License

Private - All rights reserved
