# Authentication modes

vora supports three ways of signing people in. Pick one per installation.

| Mode | Status | Who owns credentials | `AUTH_SIGN_IN` |
| --- | --- | --- | --- |
| [local](#local) | Available, default, not deprecated | vora | `local` (or unset) |
| [external](#external) | Available | A standalone auth-manager at `id.<domain>` | `provider` |
| [embedded](#embedded) | Available, opt-in | An auth-manager mounted in vora under `/auth` | `embedded` |

Local mode stays. Deprecation is deferred until embedded has shipped, has
become the default for new installs, and no installation still runs on local
(or each has migrated with the account import).

## Local

vora keeps its own accounts: passwords, email codes, passkeys, registration,
invite links, the waitlist and recovery. The credential code lives in
`bherila/auth-laravel` and is shared with other apps. Local password sign-in uses
its `PasswordCredentials` preflight before issuing the mandatory email-code
challenge. Preflight verifies credentials and account policy without creating a
login session or remember cookie. Vora owns challenge handoff, throttling and
audit calls; the shared service performs configured password rehashing.

Login and recovery pages compose `bwh-auth` forms inside Vora's card, Event
joining notice and navigation. The password reset page opts into matching
passwords and an eight-character minimum. Registration, invitations, approval,
age eligibility, and Event joining remain application policy. Intended return
URLs use the shared `IntendedUrl` helper with Vora's relative-path and strict
one-decode checks after those gates.

**Choose it** for a self-hosted single install, or when you do not want to run
another service.

**Config**

| Key | Value |
| --- | --- |
| `AUTH_SIGN_IN` | `local` (default; unrecognised values also mean local) |

Nothing else is required. `OAUTH_*` keys are ignored.

**Sign-out** ends only vora's session and returns to `/login`.

**Security notes**
- Login is throttled (`BHERILA_AUTH_THROTTLE_*`) and password sign-in is
  followed by an email 2FA challenge.
- `/oauth/callback` is not served in this mode (404).

## External

A standalone auth-manager at `id.<domain>` owns passwords, passkeys, recovery
and email addresses. vora signs people in over OAuth (authorization code with
PKCE, S256) through auth-laravel's `RelyingPartyLoginController`
(`App\Http\Controllers\Auth\ProviderLoginController`). `/login` redirects to the
provider; registration, invites, the waitlist and the local password and
passkey pages return 404. The provider admits people by granting them this
application, or through an invitation sent from vora's **Invites** page.

**Choose it** when several apps share one identity, or you want a single place
for passwords, passkeys and account recovery.

**Config**

| Key | Meaning |
| --- | --- |
| `AUTH_SIGN_IN=provider` | Turns the mode on |
| `OAUTH_PROVIDER` | Provider name; accounts bind to (name, subject) |
| `OAUTH_PROVIDER_URL` | `https://id.<domain>` |
| `OAUTH_CLIENT_ID`, `OAUTH_CLIENT_SECRET` | Client registered at the provider |
| `OAUTH_REDIRECT_URI` | `https://<site>/oauth/callback` (the default from `APP_URL`) |
| `OAUTH_SCOPE` | Default `identity:read` |
| `OAUTH_PROVIDER_ISSUER`, `OAUTH_AUTHORIZATION_RESPONSE_ISS` | Optional RFC 9207 `iss` checking |
| `BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED` | Set `true` once the provider's identity-status endpoint answers, so disabled or deleted people lose their session within five minutes |
| `BHERILA_AUTH_INVITATION_KEY_ID`, `BHERILA_AUTH_INVITATION_PRIVATE_KEY_PATH` | Optional, for email invitations |

See `.env.example` and the README section "Sign-in through an identity provider".

**Redirect URIs.** Register exactly these at the provider for the client:
- Redirect URI: `https://<site>/oauth/callback`.
- Post-logout URI: this site's home page (`https://<site>/`). A provider that
  matches it against the origin of the redirect URI needs nothing more.

**Trust boundary.** The provider authenticates; vora authorizes. vora decides
through `AuthUserPolicy` whether an account may proceed (disabled, deleted,
age gate) and where it lands. The provider is trusted for identity and
nothing else. Accounts are bound by the provider name and its opaque subject,
never by email, so a provider-side email change cannot take over a local
account. Sessions not created through the provider end at the next request.

**Install and switch (local to external)**
1. Register the client at the provider with the redirect URIs above.
2. Link existing accounts so first sign-in does not collide on email:
   `php artisan oauth:bind-subject <account-id> <subject>`. For bulk moves use
   the directory import in [auth-manager#105](https://github.com/bherila/auth-manager/issues/105).
3. Set the `OAUTH_*` keys and `AUTH_SIGN_IN=provider`.
4. `php artisan config:cache` (the mode is read from cached config).
5. Sign in. The first account to sign in becomes administrator.

**Sign-out** ends vora's session and the provider's (`/oauth/end-session`),
then returns to the home page. Script callers get `{"redirect": ...}` as JSON.

**Security notes**
- Keep `OAUTH_CLIENT_SECRET` and the invitation private key out of the web root
  and out of git.
- Use HTTPS for both origins.
- Authorization responses are bound to the session that started the flow.

## Embedded

An auth-manager instance runs inside vora under `/auth`. There is one deployment,
but the provider owns its own directory, credentials, session and prefixed tables.
vora remains an OAuth relying party: the authorize, token, identity and end-session
requests use the same protocol as external mode.

**Choose it** for a self-hosted installation that wants auth-manager's identity
features without running another service. It is opt-in; installing this release
does not change an existing installation's mode or make embedded the default.

**Config**

| Key | Meaning |
| --- | --- |
| `AUTH_SIGN_IN=embedded` | Mounts the provider and turns on provider sign-in |
| `APP_URL` | This installation's HTTPS origin, e.g. `https://app.example.test` |
| `AUTH_MANAGER_EMBEDDED_PREFIX` | Mount path, default `auth` |
| `AUTH_MANAGER_OAUTH_ISSUER` | Exact provider issuer, default `APP_URL` plus the mount path |
| `OAUTH_PROVIDER` | Stable provider name; keep it unchanged when moving the directory |
| `OAUTH_PROVIDER_URL` | Defaults to `APP_URL` plus the mount path in embedded mode |
| `OAUTH_CLIENT_ID`, `OAUTH_CLIENT_SECRET` | A confidential client registered in the embedded provider |
| `OAUTH_REDIRECT_URI` | Default `APP_URL/oauth/callback`, outside the provider mount |
| `AUTH_MANAGER_EMBEDDED_DB_CONNECTION` | Optional source connection; defaults to the host's connection |
| `AUTH_MANAGER_EMBEDDED_TABLE_PREFIX` | Default `auth_manager_`; keep it unchanged after setup |
| `AUTH_MANAGER_EMBEDDED_SESSION_COOKIE` | Default `auth_manager_session`; distinct from vora's cookie |
| `AUTH_MANAGER_EMBEDDED_KEYS_PATH` | Default `storage/app/private/auth-manager/oauth` |

Use the identity profile (`AUTH_MANAGER_PROFILE=identity`) for ordinary sign-in.
The provider shares the host's mailer, scheduler, cache and `APP_KEY`. The provider's
session cookie is host-only and scoped to the mount path. Provider administrators
manage the directory at `/auth/admin/users`; vora administrators manage application
permissions at `/admin/users`. Those are separate roles and separate people records.

**Install**

The Composer lock installs the `0.1.4` source ZIP from the signed auth-manager
release. It includes matching compiled `public/build` assets, which CI and
deployment reuse. Package
auto-discovery is disabled: vora registers
the provider only when `AUTH_SIGN_IN=embedded`. Local and external installations
serve no embedded provider routes.

1. Configure the embedded values above in private runtime configuration. Keep the
   instance closed to visitors during first setup, and configure working email.
2. Clear and rebuild cached configuration so the provider's commands are registered:

   ```sh
   php artisan config:clear
   php artisan config:cache
   ```

3. During the installation's explicitly scheduled schema update, create the provider's
   tables separately from vora's, then generate its signing keys once:

   ```sh
   php artisan auth-manager:migrate --force --no-interaction
   php artisan passport:keys --no-interaction
   ```

   These commands use the provider's connection and prefixed migration repository.
   Preserve its tables, key directory and `APP_KEY` across releases. Do not regenerate
   keys on every deploy.
4. Bootstrap the first provider administrator in an empty provider directory:

   ```sh
   php artisan ops:embedded-admin admin@example.test "Administrator"
   ```

   It prompts for a hidden password and confirmation, refuses a nonempty directory,
   and creates no vora account. For an existing provider directory, use the
   dry-run-first [identity copy procedure](https://github.com/bherila/auth-manager/blob/v0.1.4/docs/identity-copy.md),
   then grant a chosen existing identity with
   `php artisan auth-manager:user-roles admin@example.test --add=admin`.
   Neither path copies or binds vora's local accounts automatically.
5. Register vora's confidential authorization-code client in the provider. Capture
   the one-time client secret into a private file outside the repository and web root:

   ```sh
   umask 077
   php artisan passport:client --name="Example application" \
     --redirect_uri="https://app.example.test/oauth/callback" \
     --no-interaction > "$PRIVATE_DIR/vora-oauth-client.txt"
   ```

   Set `OAUTH_CLIENT_ID` and `OAUTH_CLIENT_SECRET` from that file without posting them
   in logs or tickets, remove the capture file, and rebuild cached configuration.
   Grant intended people this client in `/auth/admin/users`. The provider's
   [application connection guide](https://github.com/bherila/auth-manager/blob/v0.1.4/docs/connecting-an-application.md)
   explains client grants and optional application registration.
6. Verify a granted account can complete `/login` → `/auth/...` → `/oauth/callback`
   and reach vora, and an ungranted account cannot. Log out and check both sessions
   end. Confirm provider settings and vora settings open their respective surfaces.
   Then enable `BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED=true` and verify revocation.

**Local HTTP workers.** Embedded OAuth makes server-to-server requests back to this
same installation during the callback and provider-status checks. The HTTP server
must handle those requests while the original request is still running. For local
development on a platform supporting PHP CLI workers, run:

```sh
PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload
```

Laravel requires `--no-reload` to honor that worker setting. A plain single-worker
`php artisan serve` can stall while waiting for its own token request. PHP-FPM or
another server with concurrent workers also works. Keep `APP_URL` and the OAuth
callback URL consistent with the reachable local origin.

**Assets and deployment.** Provider pages serve compiled assets at
`/auth/build/assets/...`. CI and container builds run
`bash scripts/build-embedded-auth-assets.sh`, reusing packaged assets when present
as they are in the pinned release. The source-only development fallback builds
matching assets in a disposable directory. Deployment archives
include the provider and its matching assets under `vendor/`; the server needs no
frontend build or asset publication. When explicitly developing embedded mode
from a source-only Composer package, run that script before serving provider pages.
After caching configuration and checking the instance profile, the deploy script
runs vora's migrations and, only in embedded mode,
`auth-manager:migrate --force --no-interaction`. A failed provider migration fails
the deployment. Vora's migration repository never owns the provider's schema.

**Invitations.** Vora's `/invitations` uses the same provider invitation contract as
external mode. The signing key, provider-side public key and client permission must
still be configured; mounting the provider does not grant invitation privileges.
Use the issuer with its full mount path when configuring invitation assertions.

**Release updates.** The provider uses the stable, signed and attested `0.1.4`
[source release](https://github.com/bherila/auth-manager/releases/tag/v0.1.4),
from commit `92661dead4ff616f799a45f65e36be72bd63aa4d`. Its
[upgrade guide](https://github.com/bherila/auth-manager/blob/v0.1.4/docs/releases/v0.1.4.md)
covers directory migration, administrator controls, credential/session isolation,
passkey persistence and the schema/cache requirements for upgrading from `0.1.3`.
Embedded confirmation/report names use `auth-manager.two-factor.*`; Vora's local
credential routes remain disabled in embedded mode. Rebuild route caches when
activating this version.

The adopted `auth-manager-source-v0.1.4.zip` has SHA-256
`bdfa9d36f7798a8152eb85fd3672dbbf9c6e95609ca2204545dd59dfdd944bd5`.
Its `SHA256SUMS-source` and provenance were verified against the exact source
commit, `refs/tags/v0.1.4` and the provider's release workflow. The Composer
package definition repeats the archive's actual dependency/autoload/provider
metadata and pins its release URL, version, source reference and SHA-1 checksum.
For future updates, repeat the checksum and provenance checks in the provider's
[packaging guide](https://github.com/bherila/auth-manager/blob/v0.1.4/docs/embedded-mode.md#packaging),
then run the host and embedded CI checks against the resulting lock file.

The shared `auth-laravel` client uses the tagged `0.28.6` release, which includes
two-factor route namespaces, password preflight and intended-destination
validation alongside invitation support for mounted issuers and optional
persistent passkey login.
The Composer lock fixes the exact installed release; no development alias is
needed for the client.

The browser helper uses the published `bwh-auth` `0.6.3` npm release. Its signed
release tag and successful publication identify the reviewed source; the pnpm
lock verifies the registry artifact's integrity. The shared forms supply local
login and recovery controls, while Vora retains its surrounding workflow and
account policy. Other dependency build checks remain enabled.

**Same-origin caveat.** Because auth-manager is served from vora's origin,
its cookies, session and any script on `/auth` share an origin with vora. A
cross-site scripting flaw in either side exposes the other, and vora cannot
rely on the origin boundary that external mode gives. Treat both as one trust
domain.

**Rollback.** Before first enabling embedded, retain the previous private auth
configuration and back up the host/provider databases, keys and `APP_KEY`. To undo
a staged switch, restore the previous auth configuration, clear/rebuild the config
cache and verify sign-in using that mode. Keep the provider tables and keys; do not
run migration rollbacks or delete its directory. After people begin using a new
directory, switching credentials back is a migration and must be rehearsed rather
than treated as a config-only rollback.

**Switching** from local or external to embedded means moving accounts between
directories. For local accounts, use the dry-run-first
[local identity migration](local-identity-migration.md), with a same-database
embedded destination. Copying an existing provider directory preserves subjects;
keep `OAUTH_PROVIDER` unchanged.

## Moving between modes

Accounts are keyed differently per mode (local email and password versus
provider name and subject), so a switch is an account migration, not just a
config change. For bulk local-to-provider moves, use
[`ops:local-identities`](local-identity-migration.md): it creates owned embedded
identities and exact bindings atomically, with a default dry run and resumable
per-account commits. A manually prepared identity can still be bound individually
with `oauth:bind-subject`; the bulk tool refuses to adopt such an existing binding
without its own ownership checkpoint.

For embedded-to-external, follow the provider's
[portable directory procedure](https://github.com/bherila/auth-manager/blob/v0.1.4/docs/portable-directory.md)
to preserve exact subjects, compatible credentials, client registrations and grants
in a fresh destination. Keep `OAUTH_PROVIDER` unchanged so vora's bindings identify
the same people. Verify the destination's client registration and supported secret,
change the provider base URL/issuer and set `AUTH_SIGN_IN=provider`.
The archive excludes runtime keys, sessions and transient sign-in material; align
the destination's sign-in assurance, password hasher and effective passkey RP ID as
the procedure requires. Rehearse the complete move on an isolated copy; this
release performs no account migration.

## Tests

`tests/Feature/Auth/AuthModesTest.php` covers mode selection and route resolution.
`AuthFlowTest` and `ProviderSignInTest` cover local and external credential flows.
`tests/Feature/Auth/EmbeddedAuthTest.php` covers the mounted provider's isolation;
`tests/embedded/auth.spec.ts` exercises real HTTP OAuth sign-in and sign-out through
the mounted provider. Full suites and browser builds run in CI.
