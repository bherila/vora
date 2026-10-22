# Event instances

One codebase supports independent community and Event deployments. Each instance
has its own database, application key, sessions/cache namespace, mail/passkey
settings, photo bucket, video input bucket, and HLS output bucket. Accounts and
Saves belong to that instance. There is no shared tenant database or cross-instance
account lookup.

Set `APP_PROFILE=event` in the instance's server `.env` and give it its own
`APP_NAME` and `APP_URL`. The default `vora` profile retains community behavior.
Event enables Events, Library, and Favorites, and disables Personas, Stories,
Chat, community feeds, social profiles, and Interests. Optional
`MODULE_<NAME>_ENABLED=true|false` overrides control individual modules. Disabled
modules return 404 from their pages and APIs; existing records remain stored.
Changes take effect after refreshing Laravel's configuration cache. Inspect the
resolved settings with `php artisan ops:instance-profile --expect=event`.

## Attendee experience

Approved attendees see Events and Attendees. They choose an Event before uploading
photos or videos, then browse that Event or an attendee's uploads. Each upload has
one owning Event. An Event is visible either to all approved, active attendees or
to explicitly selected attendees. Administrators create and edit Events, their
names, dates, IANA timezones, descriptions, and membership lists. Administrators
also select featured curators; their favorites appear together in the featured
view. Any attendee can browse another attendee's favorites within their own Event
access. Restricted Event titles and media are omitted from unauthorized views.

Favorites are public annotations within the viewer's existing access. Save adds
a private Library reference, without copying the object. A saved item remains
available privately after an Event grant is removed or its uploader is deleted.
It cannot grant someone else access through a shared collection. Moderation
removal still takes precedence. Download explicitly transfers the original file;
video playback continues to use HLS. A browser/OS decides whether a download can
be moved directly into the device's camera roll.

The existing verified-email, account approval, two-factor authentication,
eligibility, quota, and media review policies apply. New uploads await media
review before other attendees can see them. Event membership does not bypass
moderation or account restrictions. Set the instance’s default storage quota in
Admin → Users; unset account quotas inherit it, and explicit account overrides
still apply. Uploads and durable Saves both count toward account usage. An
uploader retains private management access
to their own files if their Event membership changes. Deleting Events is not
exposed: changing visibility or membership preserves their media's access boundary.

## Deployment target

The primary `prod` deployment continues automatically on `main`. Additional
instances use **Release instance → Run workflow** with a GitHub environment name
and the intended branch/tag. Create the environment before running a release and
configure its own secrets:

- `SSH_HOST`, `SSH_USERNAME`, `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`.

Configure environment variables:

| Variable | Example | Meaning |
| --- | --- | --- |
| `DEPLOY_ENABLED` | `true` | Enables the deployment job |
| `DEPLOY_PROFILE` | `event` | Must match the server's `APP_PROFILE` |
| `DEPLOY_PATH` | `event-app` | Application path relative to SSH user's home |
| `DEPLOY_PHP_BINARY` | `/opt/cpanel/ea-php85/root/usr/bin/php` | PHP executable |
| `DEPLOY_WEB_ROOTS` | `event.example.com` | Optional comma-separated web root paths relative to home |
| `DEPLOY_HEALTH_URL` | `https://event.example.com` | Public origin checked after deployment |

The legacy primary target retains its existing defaults. Other environments must
provide paths, profile, PHP binary, and health URL explicitly. Existing web root
directories are never removed: configured web roots must be absent or symlinks.
Alternatively leave `DEPLOY_WEB_ROOTS` empty and configure the web server's document
root directly to the application's `public` directory. Provision the application
directory with its own `.env` first. The workflow preserves `.env` and `storage`,
checks the configured profile, applies migrations, clears compiled views, and
checks public pages plus scheduler/queue health. Configure a scheduler cron for
each application's own path as shown in the README before its first release.

Use separate database credentials and buckets even on a shared host. Set
`AWS_BUCKET` for video inputs, `PHOTOS_BUCKET` for photos, and `HLS_BUCKET` for HLS
outputs, with independent credentials where needed (`.env.example` documents the
fallbacks). Private buckets stay private. Add each instance's exact origin to
its upload bucket's CORS rules and passkey origins. Never reuse `APP_KEY`,
session cookies, cache prefixes, or Redis namespaces between instances.

A shared transcoder may process several named input/output bucket pairs with a
single global worker/resource budget. Each instance needs a distinct HLS output
bucket: cleanup and content reference ownership are scoped to that destination.
See the [shared transcoder guide](https://github.com/bherila/s3-hls-transcoder/blob/main/docs/MULTI_TENANT.md)
for its pair configuration and fairness/checkpoint behavior. Adding an Event app does not
require copying the transcoder deployment.

## Validation and reusable local staging

GitHub CI runs the complete frontend/backend gates and isolated MariaDB suite.
Event tests cover restricted membership, viewer-filtered favorites, asset and
HLS authorization, upload binding, quota, and private retained Saves. The real
media staging workflow exercises Docker uploads/playback independently of any
production buckets.

For a reusable local instance, set `LOCAL_STAGING_APP_PROFILE=event` when starting
`docker/local/compose.yaml`. It defaults to `vora`. Follow the existing explicit
SQLite initialization instructions in `docker/local/README.md`; changing profiles
does not initialize or reset data. Keep disposable volumes for reuse, and run
`docker compose -f docker/local/compose.yaml stop` when finished.
