# GitHub Actions Deployment

This directory contains the GitHub Actions workflow for deploying the application.

## Setup

This workflow uses a GitHub Environment named `prod`. You will need to create this environment in your repository settings (`Settings > Environments > New environment`).

Once the `prod` environment is created, you need to configure the following secrets as **Environment secrets** within that environment:

1.  `SSH_HOST`: The hostname or IP address of the deployment server.
    -   Example: `your.server.com`

2.  `SSH_USERNAME`: The username for SSH login.
    -   Example: `your_user`

3.  `SSH_PRIVATE_KEY`: The private half of a dedicated Ed25519 deploy key.
    -   Generate: `ssh-keygen -t ed25519 -C "deploy@your-repo" -f deploy_key`
    -   Add the public key (`deploy_key.pub`) to `~/.ssh/authorized_keys` on the server.
    -   Paste the private key (`deploy_key`) as this secret.

4.  `SSH_KNOWN_HOSTS`: The pinned host key entry for the deployment host, in `~/.ssh/known_hosts` format. This enables `StrictHostKeyChecking=yes` so the deploy fails closed instead of trusting whatever host answers (MITM protection).
    -   Generate from a trusted machine: `ssh-keyscan -H your.server.com`
    -   The hostname in this output must exactly match `SSH_HOST`.

## Deployment Target


The workflow deploys the application to the `~/laravel/` directory on the remote
server and exposes `~/laravel/public/` through the cPanel document-root symlinks.
The application directory must remain group-traversable (`710`) so LiteSpeed can
reach public files without making the application contents group-readable.

After deployment, the workflow verifies `/up`, `/`, and `/login` through the
public Cloudflare path, following redirects and requiring a final HTTP 200.
The normal web pages are checked because `/up` can succeed while user-facing
pages return a LiteSpeed 503.

`public/.htaccess` sets the LiteSpeed web worker's PHP `memory_limit` to `1024M`,
matching the SVC web-handler configuration. Artisan's CLI PHP limits are separate.
When diagnosing a 503, inspect the host PHP/LiteSpeed error log as well as
`storage/logs/laravel.log`; a successful CLI command or `/up` response alone does
not establish that normal web requests work. The setting does not override
hosting-account resource limits.

Immediately after migrations, deployment also runs `schema:assert-persona`.
This fails the deployment immediately when any database column required by the
persona models is missing. The assertion complements `/up`: the health endpoint
can stay green when reads degrade but persona writes cannot succeed.

The deployment workflow runs `ops:queue-health --json` after each release. A
failing check requires inspection of the cPanel cron and
`storage/logs/scheduler.log`; failed jobs must be investigated before they are
retried or removed. The command can also be run manually without starting a
recurring GitHub Actions workflow.

## Real media staging acceptance

`real-media-staging.yml` builds the reusable Docker stack and tests normal SMTP
two-factor login, real photo/video uploads, FFmpeg HLS playback, quota limits,
bulk organization, durable saves after uploader deletion, and final-reference
cleanup. It uses generated media, disposable accounts, isolated SQLite and
private MinIO buckets, with no production credentials. Images build sequentially
with layer caching; services stop after the run and reports are retained for
seven days. See [the runner instructions](../../tests/staging/README.md).

## Migration amendments (CI guard)

`ci.yml` fails a pull request that **modifies** an existing file under
`database/migrations/`. Only adding new migrations is allowed by default.

Laravel records applied migrations by filename. Editing one in place is
therefore silently skipped on any database that already ran the original — the
change reaches fresh installs only. This is not hypothetical: `characters.ulid`
and `characters.is_linked` were added by amending
`create_characters_table` in place, so production (which auto-deploys from
`main` and had run that migration months earlier) never received the columns,
while the deployed code required them. Reads degraded to `null` and every
persona write failed; `/up` stayed green throughout, because the app was up.

**To change a shipped schema, add a new migration.**

If a migration has genuinely never been deployed anywhere — a brand-new table
added earlier in the same unreleased branch, for example — label the PR
`migration-amend-ok` to bypass the check. Prefer adding a migration; the label
exists for the rare case where amending is genuinely correct.
