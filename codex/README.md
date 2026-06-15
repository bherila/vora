# Codex Cloud Setup

Use these checked-in scripts for Codex Cloud environment configuration.

Setup script:

```bash
bash codex/setup.sh
```

Maintenance setup script:

```bash
bash codex/maintenance.sh
```

`setup.sh` assumes the Codex image already provides Bash, curl, PHP 8.3 or
newer, Node.js, and either `pnpm` or Corepack. It uses tools already available
on `PATH` when possible, installs Composer into `$HOME/.local/bin` only when
missing, and activates pnpm through Corepack only when `pnpm` is missing. It
then installs Node and PHP dependencies from the committed lockfiles in
parallel.

For Laravel convenience, the script creates `.env` from `.env.example` when
missing and generates an `APP_KEY` when the local environment does not already
have one. It deliberately does not run migrations, schema dumps, production
builds, or tests.

`maintenance.sh` reuses the same setup path with Composer optimized autoloading
enabled for the cached environment.

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
