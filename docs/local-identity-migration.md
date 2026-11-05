# Moving local accounts to the embedded provider

`ops:local-identities` plans or applies a local-account migration. It never changes
the installation's configuration or opens the site to visitors. Start on an
isolated copy, then schedule a quiesced maintenance window for the real move.

The supported destination is the embedded provider in the **same physical
database**, with its separate prefixed tables. Separate databases, read/write
splitting and pre-existing transactions are refused. A move to an external
provider has two stages: migrate local accounts to embedded, then move the
provider directory to the standalone instance with the provider's portable
export/import tooling, preserving subjects and `OAUTH_PROVIDER`.

## Prepare

1. Back up the host database, provider directory, configuration, `APP_KEY`,
   signing keys and passkey settings. Rehearse restoration on a copy.
2. Stop account registration, credential changes, sign-ins and background jobs
   that can alter identities during the real run. Keep the site closed through
   verification. The command locks each source account and its credentials,
   but does not coordinate a live application's workers or switch its auth mode.
3. Stage embedded configuration and run the ordinary host and provider migrations
   using the deployment procedure. Start with an empty provider directory;
   **do not run `ops:embedded-admin` first**. Existing provider subjects or email
   addresses are conflicts, even when an address matches the local account.
4. Register a confidential Vora authorization-code client, with the exact callback
   URI, and configure its `OAUTH_CLIENT_ID` and secret. Leave its user provider
   unset or select `auth-manager-users`; the host's `users` provider is refused.
   Keep `OAUTH_PROVIDER` stable. The command verifies the client secret with the
   provider's configured hash verifier before any migration or sequence write.
5. Preserve local password-plus-email-code assurance:
   `AUTH_MANAGER_REQUIRE_CODE_AFTER_PASSWORD=true` and
   `AUTH_MANAGER_ALLOW_CODE_ONLY_SIGN_IN=false`. Verify mail delivery on the
   staged instance before reopening it. Both settings are required even for a
   dry run; there is no automatic assurance downgrade.

The CLI must boot with `AUTH_SIGN_IN=embedded`, the intended `APP_URL`, mount and
issuer. A cached Laravel configuration ignores ad hoc environment overrides;
use the staged configuration deliberately. Do not assume prefixing an Artisan
command with an environment variable changes an already cached mode.

## Plan and apply

```sh
php artisan ops:local-identities
php artisan ops:local-identities --json
php artisan ops:local-identities --user=42 --user=81
```

The default is a deterministic dry run. It changes no accounts, hashes,
credentials, bindings, grants, checkpoints or identifier sequences. Reports show
local account IDs and reason codes, without names, addresses or credential data.
Unknown selected IDs fail preflight rather than being silently omitted.

Resolve conflicts and unsupported credentials before proceeding. Then repeat the
same selection with `--apply`:

```sh
php artisan ops:local-identities --apply
```

Each completed account is committed separately. A failure rolls back that
account's provider user, credentials, client grant, local binding, audit entry
and ownership checkpoint together. Other completed accounts remain committed.
Rerun the same command to resume. A verified checkpoint reports `unchanged`;
changed source credentials, a changed binding, missing provider subject or a
different destination reports a conflict and is never overwritten.

Before account transactions, apply reserves the provider's next numeric subject
above every allocated source ID, including unselected, deleted and formerly
allocated IDs. This sequence reservation can remain after a failed account or
interruption. It creates no identity and is intentionally never lowered. MySQL
and MariaDB perform it outside transactions because changing `AUTO_INCREMENT`
is DDL.

## Passkeys

Accounts holding passkeys require an explicit choice. The default reports
`passkey_decision_required` and migrates none of that account's identity.

To copy passkeys on an unchanged origin and relying-party ID:

```sh
php artisan ops:local-identities --copy-passkeys \
  --rp-id=example.test --origin=https://app.example.test
php artisan ops:local-identities --copy-passkeys \
  --rp-id=example.test --origin=https://app.example.test --apply
```

The confirmation must match the staged origin and effective host/provider RP
settings. Every credential must record that RP ID; unknown legacy RP IDs are
refused. The provider cannot accept additional origins or weaker user
verification. Credential IDs, public keys, counters and metadata are copied;
source credentials remain untouched. New provider subjects equal local user IDs
because auth-laravel derives WebAuthn user handles from those IDs.

Alternatively, `--leave-passkeys` explicitly performs a password-only migration.
The report names the omission, and local passkeys remain stored. Arrange
provider-side enrollment after the move; local passkeys are unavailable once the
installation uses provider sign-in. The command never deletes or revokes them.
Unsupported password algorithms report `password_reset_required` without
creating a provider identity or changing the local hash. Reset such passwords
through the existing local workflow before retrying; hashes are never rehashed
as if they were plaintext.

## Account state, ownership and verification

Vora IDs, posts, personas, profiles, permissions, approval and credentials remain
in place. Only the exact provider/subject binding is added to a local account.
Every newly created provider identity gets the `user` role, including a Vora
administrator. Provider administration is a separate, deliberate operator step:

```sh
php artisan auth-manager:user-roles admin@example.test --add=admin
```

Disabled, deleted, unapproved, unverified, banned, deactivated and forced-password-
change accounts receive disabled provider identities and **no client grant**.
Their local flags remain unchanged. A deleted source remains deleted at the
provider. Later restoration, approval or password recovery needs an explicit
review on both sides; rerunning the migration never enables an account or
restores a revoked grant.

The host `local_identity_migrations` table is ownership proof committed with the
new provider subject and binding. Retain it and its backups. An existing
provider account is never adopted by email or numeric ID alone. Existing
bindings without this checkpoint require manual review; this tool does not
reinterpret them as a successful migration.

Before reopening, verify password-plus-code and passkey sign-in with an eligible
account, access to the original Vora profile and content, logout, and refusal of
disabled/deleted/pending accounts. Confirm Vora and provider administrator roles
separately. After provider use begins, rollback is another credential migration:
do not blindly restore old password or passkey counters. Keep the ownership
ledger, provider tables, signing keys and both databases while rehearsing that
rollback. This command performs no live cutover or automatic reverse migration.
