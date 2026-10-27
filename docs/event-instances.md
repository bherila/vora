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
review before other attendees can see them unless an administrator enables
**Skip Event upload review** for that uploader in **Admin → Users** or
**Manage attendees**. The
exemption defaults off and applies only to new Event upload completions, using
the same immutable reviewed copies as manual approval. Gallery, profile and
private Library uploads still need review. Removing an exemption keeps already
approved items approved; existing pending or rejected media are not reapproved.
If reviewed-copy storage fails, the completed upload remains awaiting review.
Event membership does not bypass
moderation or account restrictions. Set the instance’s default storage quota in
Admin → Users; unset account quotas inherit it, and explicit account overrides
still apply. Uploads and durable Saves both count toward account usage. An
uploader retains private management access
to their own files if their Event membership changes. Deleting Events is not
exposed: changing visibility or membership preserves their media's access boundary.

Administrators set the minimum account age and optional manual age confirmation
in **Invites & Signups**. Defaults remain 18 years and no manual verification.
Leave the minimum blank to disable it; birth dates remain required for registration
and invitation requests. This software policy does not determine legal eligibility.
When manual verification is enabled, an administrator confirms the attendee's age
using the existing verification control in **Users**; the site does not collect
identity-document uploads. Changing a recorded birth date clears prior verification.
Policy changes apply to existing sessions immediately: attendees who no longer
qualify see an explanation and can still manage, export or remove their account.
Administrators retain access to change the policy. The built-in Terms reflect the
current settings; review custom Terms when changing admission policy.

## Organizer attendee management

Administrators open **Manage attendees** at `/events/organizer` to search accounts,
filter pending approvals, approve verified eligible attendees, change selected
Event access, and control per-attendee upload review. Email addresses and these
controls are visible only to administrators. Membership changes affect the
selected attendee without replacing anyone else's grants. Everyone Events remain
accessible to all approved eligible attendees; administrators can access every
Event regardless of explicit selections. Previously Saved items retain their
private access when membership is removed.

Account signup invitations and Event membership are separate. A joining link or
an email invitation does not grant selected-attendee access. Organizers manage
those grants inside the app after account admission.

## Phone photos and original files

Still HEIC and HEIF photos preserve the original upload bytes. The browser
generates a JPEG display preview, at most 2048 pixels on its longest edge and
5 MiB. Browsers without native HEIC support load the bundled decoder when needed.
Conversion is sequential and cancellable; the app server does not decode images.
A failed conversion affects only that file in a batch. HEIC sequences are not
accepted as still photos; upload a still photo and video separately for paired
media.

Preview and original follow the same private access, immutable review snapshots,
and durable-Save lifecycle. Downloads return the original HEIC/HEIF bytes.
Administrators check the stored original through an independent browser decode
before approving HEIC media; an uploader-supplied preview alone does not establish
what the original contains.

### Home Screen app and sharing

The site can be added to a phone's Home Screen under the installation's
`APP_NAME` (or a shorter Home Screen name) and the icon set in **Admin → App
icon & name**. On Android, the installed
app also appears in the share sheet: photos and videos shared from the Photos
app open the gallery's upload dialog with the files queued. The service worker
receives the share and holds the files in the browser until the gallery takes
them; nothing is uploaded until the attendee chooses an Event and confirms.
iOS does not let web apps receive shares, so iPhone users upload from the
gallery's **Add photos & videos** button.

The Event gallery groups likely cross-format photo versions while preserving
every original and its metadata. See [Event photo versions](event-photo-versions.md)
for matching limits, preferred-version ranking and private Save behavior.

## Bulk Save and download

Select accessible Event media to Save privately or download originals as a ZIP.
Library selections support the same actions where applicable. Bulk Save uses
ordinary quota and retained-reference rules, applies atomically, and does not
copy stored media. ZIP requests support at most **50 files and 2 GiB** total.
Larger videos can be downloaded individually, including retained Library items.
Shared downloads use opaque filenames to protect uploader filename metadata.

Archives stream to the browser's download manager without a server archive file
or a JavaScript Blob containing the archive. S3 reads use streaming mode;
underlying reads are capped at 64 KiB, files use ZIP STORE, and directory memory
is bounded by file count. The ZIP library also uses a fixed internal buffer; a
large synthetic-source regression checks working memory, rather than claiming
memory use is exactly one chunk. Selections are checked before streaming and
between files. Revocation, storage failure or disconnect stops the archive.
Downloads use short-lived session-bound tickets and a per-user concurrency lock.

## Joining links and admission

Use an Event's **Copy joining link** action to copy `/events/join/{ULID}` on
the current instance. The complete URL can be encoded in a QR code without a
special token or QR service. A joining link remembers a destination; it does
not create membership or bypass account approval, eligibility, or media rules.
An Event joining URL is not a personal signup invitation. The organizer must
enable public sign-ups (with the existing approval policy) or issue each new
attendee a separate personal `/i/{UUID}` signup invitation. Open that invitation
in the same browser after the Event link to retain its destination. Existing
invitation expiry, single-use, email binding, and approval policies still apply.
Restricted and missing Events reveal no Event name, location, or membership
before admission and return the same not-found response to unauthorized users.

The same browser session retains the Event while switching between login and
registration, completing password/email two-factor login, verifying email,
and waiting for account approval. Verification and pending-approval screens
explain the next step and provide continuation buttons. After approval, Event
access is checked again; the organizer must separately add attendees to a
restricted Event. Forced password changes still take precedence.

Email verification links remain signed and account-bound. If email opens in a
different browser or on another device, that browser does not inherit the
original joining session. Reopen the original joining link or scan its QR code
in the browser where you sign in; it will restore the Event destination without
transferring a login or granting access. Keep the joining link until admission
is complete. See [Event UX validation](event-ux-validation.md) for phone/pilot
checks.

## Deployment target

The primary `prod` deployment continues automatically on `main`. Additional
instances use **Release instance → Run workflow** with a GitHub environment name
and the intended branch/tag, or set `source_ref` to a Vora commit, tag, or branch.
When omitted within the Vora repository, the exact workflow-triggering commit is
built, preserving the primary production behavior. Create the environment before
running a release and configure its own secrets:

- `SSH_HOST`, `SSH_USERNAME`, `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`.

Store deployment targets as environment secrets so public workflow logs mask
their values. Keep concrete deployment details in private operational records;
the examples below are placeholders.

| Secret | Example | Meaning |
| --- | --- | --- |
| `DEPLOY_PATH` | `event-app` | Application path relative to SSH user's home |
| `DEPLOY_PHP_BINARY` | `/usr/bin/php` | PHP executable |
| `DEPLOY_WEB_ROOTS` | `event.example.com` | Optional comma-separated web root paths relative to home |
| `DEPLOY_HEALTH_URL` | `https://event.example.com` | Public origin checked after deployment |

Configure environment variables separately:

| Variable | Example | Meaning |
| --- | --- | --- |
| `DEPLOY_ENABLED` | `true` | Enables the deployment job |
| `DEPLOY_PROFILE` | `event` | Must match the server's `APP_PROFILE` |

All environments must provide target paths, PHP binary, and health URL explicitly.
Existing web root
directories are never removed: configured web roots must be absent or symlinks.
Alternatively leave `DEPLOY_WEB_ROOTS` empty and configure the web server's document
root directly to the application's `public` directory. Provision the application
directory with its own `.env` first. The workflow preserves `.env` and `storage`,
checks the configured profile, applies migrations, clears compiled views, and
checks public pages plus scheduler/queue health. Configure a scheduler cron for
each application's own path as shown in the README before its first release.

An instance repository can call the public reusable workflow. Pin both the
workflow version and `source_ref` to reviewed Vora commit SHAs; they select the
deployment logic and application source respectively:

```yaml
jobs:
  release:
    uses: bherila/vora/.github/workflows/release-instance.yml@VORA_WORKFLOW_COMMIT_SHA
    with:
      environment: wedding
      source_ref: VORA_APPLICATION_COMMIT_SHA
    secrets: inherit
```

The caller needs `contents: read` permission. Create the selected GitHub
environment, deployment variables, and SSH secrets in the calling repository.
Cross-repository calls must supply `source_ref`: the workflow always checks out
`bherila/vora`, never the caller's source or commit. The build job records the
resolved Vora commit in the workflow summary. `primary` remains false for these
additional instances, so primary production target defaults do not apply.

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
`docker/local/compose.yaml`. It defaults to `vora`. Profile changes require
recreating the containers (`up -d --no-build --force-recreate`); `start` reuses
their previous environment. Follow the existing explicit
SQLite initialization instructions in `docker/local/README.md`; changing profiles
does not initialize or reset data. Keep disposable volumes for reuse, and run
`docker compose -f docker/local/compose.yaml stop` when finished.
