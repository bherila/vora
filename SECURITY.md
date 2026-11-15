# Security assumptions

What vora trusts and does not verify itself. Each entry names the code that makes the
assumption, so a change to that code is a prompt to revisit the entry. The invariants CI
enforces are listed under "Security invariants" in [AGENTS.md](AGENTS.md).

## Deployment

- **Only `main` can deploy, provided the repository settings say so.** `deploy.yml` runs on
  `push` to `main` alone. `release-instance.yml` can also be dispatched or called, so both of
  its jobs refuse a commit that is not on `main` ancestry before they build or receive a
  deployment secret, and `WorkflowCredentialCensusTest` keeps every credentialed job that way.
  But workflow files are part of the branch: anyone who can push a branch can edit them there,
  and the census cannot see that. The real control is a repository setting: each deployment
  Environment's **Deployment branches** must be restricted to `main`, and pushes to `main`
  must be restricted to its maintainers.
- **An instance repository chooses the deployment logic it runs.** A cross-repository caller
  pins `release-instance.yml@<sha>` ([docs/event-instances.md](docs/event-instances.md)), and
  its own Environment holds the secrets. The ancestry checks only protect it when that pin is a
  reviewed `main` commit, and its Environment's protection rules are that repository's
  settings, not this one's.
- **The deployed archive is the one the run's `test` job built.** The `deploy` job re-checks
  that the commit it was handed is on `main`, then deploys the artifact uploaded earlier in the
  same run; it does not rebuild or re-hash it.
- **The deploy host is the one whose key was pinned.** Every `ssh` and `rsync` uses
  `StrictHostKeyChecking=yes` against the Environment's `SSH_KNOWN_HOSTS`, so the deploy is only
  as trustworthy as the machine that captured that entry.

## Signed URLs and storage

- **`APP_KEY` stays secret.** The `local` disk sets `serve => true` (`config/filesystems.php`),
  so the framework registers `GET` and `PUT storage/{path}`, which accept any URL signed with
  `APP_KEY`. vora issues no local upload URLs, but whoever can sign one can write that path;
  nothing checks the session. The signed `PUT` is unthrottled (it is in
  `RouteThrottleCensusTest`'s allowlist) and has no app-side size limit, so the web server's
  request-body limit is its only cap.
- **Presigned object-storage URLs are bearer tokens.** `FileStorageService` hands out
  presigned download and upload URLs that grant access to anyone holding them until they
  expire. Upload URLs sign `Content-Length` and `Content-Type`, so the store refuses a body of
  another size or type (`LengthBoundPresignTest`).

## Requests

- **The client IP is the TCP peer unless proxies are configured.** `bootstrap/app.php` trusts
  no proxies, and `bherila/auth-laravel`'s `TrustedProxies` applies only with
  `BHERILA_AUTH_TRUSTED_PROXIES=true`. Until then `$request->ip()`, and every guest rate-limit
  bucket keyed on it (`App\Support\RateLimits`), is the address of whatever connects to PHP:
  behind a CDN every guest shares the edge's bucket. Trusting proxies too broadly lets a client
  choose its own IP with `X-Forwarded-For`, which is why the package trusts only the CDN's
  published ranges.
- **Production serves HTTPS and sets `SESSION_SECURE_COOKIE=true`.** `config/session.php` reads
  the cookie's `secure` flag from that variable and `.env.example` leaves it unset, so without
  it the session cookie is also sent over plain HTTP. Cookies are `http_only` and
  `same_site=lax` by default.
- **Mail webhook senders hold their secret.** `BrevoMailWebhookController` accepts any request
  carrying `services.brevo.webhook_secret`; `SesMailWebhookController` verifies the SNS
  signature and the configured topic ARN. Both answer 404 until configured.
- **Push services are the listed hosts.** Web Push subscriptions are accepted only for the
  hosts in `config('webpush.endpoint_hosts')` (`WebPushEndpoint`), and delivery sends
  requests there.

## Identity

- **The identity provider is trusted for identity, and its subject is permanent.** In provider
  sign-in, accounts are bound by provider name and the provider's opaque subject, never by
  email ([docs/auth-modes.md](docs/auth-modes.md)). vora relies on the provider never
  reassigning a subject to another person; it decides itself whether an account may proceed.
- **In local sign-in, a mailbox proves ownership of its address.** Sign-in codes, password
  resets and email-change confirmations are sent by email, so whoever reads an account's
  mailbox can reset its password and receive its sign-in codes.
- **Embedded auth shares vora's origin.** When the auth manager is served from vora's origin, a
  script injection flaw in either exposes the other; treat them as one trust domain
  ([docs/auth-modes.md](docs/auth-modes.md)).
