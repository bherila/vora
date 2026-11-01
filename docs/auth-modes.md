# Authentication modes

vora supports three ways of signing people in. Pick one per installation.

| Mode | Status | Who owns credentials | `AUTH_SIGN_IN` |
| --- | --- | --- | --- |
| [local](#local) | Available, default, not deprecated | vora | `local` (or unset) |
| [external](#external) | Available | A standalone auth-manager at `id.<domain>` | `provider` |
| [embedded](#embedded) | Coming ([auth-manager#104](https://github.com/bherila/auth-manager/issues/104)) | An auth-manager mounted in vora under `/auth` | not yet |

Local mode stays. Deprecation is deferred until embedded has shipped, has
become the default for new installs, and no installation still runs on local
(or each has migrated with the account import).

## Local

vora keeps its own accounts: passwords, email codes, passkeys, registration,
invite links, the waitlist and recovery. The credential code lives in
`bherila/auth-laravel` and is shared with other apps.

**Choose it** for a self-hosted single install, or when you do not want to run
another service.

**Config**

| Key | Value |
| --- | --- |
| `AUTH_SIGN_IN` | `local` (default; any value other than `provider` means local) |

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

Coming; tracked in [auth-manager#104](https://github.com/bherila/auth-manager/issues/104).
The planned setup mounts an auth-manager instance inside vora's own app under
`/auth`, so there is one deployment and one database but vora still delegates
credentials to auth-manager's code rather than its own.

This section describes the plan and will be updated when #104 ships. Config
keys and install steps are not final.

**Choose it** (once available) for a self-hosted install that wants
auth-manager's identity features without running a second service. It is
planned to become the default for new installs.

**Same-origin caveat.** Because auth-manager is served from vora's origin,
its cookies, session and any script on `/auth` share an origin with vora. A
cross-site scripting flaw in either side exposes the other, and vora cannot
rely on the origin boundary that external mode gives. Treat both as one trust
domain.

**Switching** from local or external to embedded means moving accounts between
directories. Use the import and export in
[auth-manager#105](https://github.com/bherila/auth-manager/issues/105).

## Moving between modes

Accounts are keyed differently per mode (local email and password versus
provider name and subject), so a switch is an account migration, not just a
config change. Plan it with the directory import and export in
[auth-manager#105](https://github.com/bherila/auth-manager/issues/105), and
test on a copy first.

## Tests

`tests/Feature/Auth/AuthModesTest.php` covers mode selection, route
resolution and sign-out for local and external. `AuthFlowTest` and
`ProviderSignInTest` cover the full flows. Embedded has a skipped placeholder
that waits on #104.
