# Codex Cloud Setup

Set **both** the setup script and the maintenance script in the Codex Cloud
environment configuration to the same command:

```bash
bash codex/setup.sh
```

Never paste a script into the environment settings: a pasted copy is untracked and
untested, and drifts. `setup.sh` is idempotent, so the maintenance run (a cached
container resuming on a newer commit) repeats it and only the dependency installs
do any work.

`setup.sh` assumes the Codex image already provides Bash, curl, PHP 8.5 (the
environment's configured runtime), Node.js, and `npm` (or Corepack). It
uses tools already available on `PATH` when possible, installs Composer into
`$HOME/.local/bin` only when missing, and installs the pinned pnpm through npm
(see below) only when the installed pnpm is not that version. It validates the
locked PHP platform and installs Composer dependencies before the more verbose pnpm installation so PHP failures
remain visible in Codex logs.

For Laravel convenience, the script creates `.env` from `.env.example` when
missing and generates an `APP_KEY` when the local environment does not already
have one. It deliberately does not run migrations, schema dumps, production
builds, or tests.

It does not pass `--optimize-autoloader`: a dev container's classmap would go
stale as a session adds and moves classes.

Codex runs setup as root, so `setup.sh` exports `COMPOSER_ALLOW_SUPERUSER=1` and
detaches stdin before any Composer call. Without that, Composer's
`Continue as root/super user [yes]?` prompt blocks until the 3600-second startup
timeout.

## Environment Variables

Required Codex secret:

- `GITHUB_TOKEN` - used for GitHub-hosted dependencies. This repo pulls
  `bherila/auth-laravel` through Composer and `bwh-auth` through a GitHub
  release tarball. The setup script passes the token to pnpm through a temporary
  process-local npm config and to Composer through process-local `COMPOSER_AUTH`;
  it does not write persistent auth files.

Required app or integration secrets for baseline setup:

- None.

Tasks that need external services will need the relevant secrets or variables
added before they can run against those services:

- Media storage and HLS: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
  `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`,
  `AWS_USE_PATH_STYLE_ENDPOINT`, plus `PHOTOS_*` or `HLS_*` overrides when those
  buckets use separate credentials or endpoints.
- Mail and auth emails: the relevant `MAIL_*` settings or provider tokens such
  as `POSTMARK_TOKEN` or `RESEND_KEY`.
- Passkey browser-origin testing: `WEBAUTHN_ALLOWED_ORIGINS` for the tested
  origin. This is configuration, not a secret.
- Production deploys from GitHub Actions: `DEPLOY_ENABLED=true` plus
  `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`, `SSH_USERNAME`, and `SSH_HOST` as
  variables or secrets accessible to the deploy workflow.
## pnpm version

`package.json`'s `packageManager` pins pnpm (for example `pnpm@12.11.2`). A pnpm of any other
version switches itself by downloading the pinned release (`@pnpm/exe.<platform>`) with Node's
built-in `fetch`, which ignores the sandbox egress proxy and fails with "Could not reach
https://registry.npmjs.org/@pnpm/exe.linux-x64/…: fetch failed" (even `pnpm --version` does
this). So `ensure_pnpm` installs the exact pinned version with `npm install --global --prefix
~/.local` (npm honours the proxy), falls back to Corepack, and exits with an error rather than
continuing on the wrong version. It checks versions with
`npm_config_manage_package_manager_versions=false` so the check itself never triggers a
download, and exports `NODE_USE_ENV_PROXY=1` so Node's `fetch` uses the proxy where the Node
version supports it.
