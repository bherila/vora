# Real local staging acceptance

This opt-in Playwright runner uses the normal Docker application, real SMTP
messages captured by Mailpit, private MinIO uploads, moderation copies, and real
FFmpeg HLS playback. It does not start the PHPUnit browser bridge or register
fixture endpoints. It is separate from the default `tests/browser` suite.

Start and explicitly initialize the disposable stack using
[the Docker instructions](../../docker/local/README.md). Leave its four seeded
accounts intact. Install the frozen pnpm dependencies and Chromium, and ensure
`ffmpeg` with libx264/AAC support is available on the host. Run from the repo root:

```bash
LOCAL_STAGING_ACCEPTANCE=1 ALLOW_DISPOSABLE_ACCOUNT_PURGE=1 \
  pnpm exec playwright test --config tests/staging/playwright.config.mjs
```

Run one acceptance process at a time; concurrent runs share recipient quota settings.
The **Real media staging acceptance** GitHub Actions workflow also runs this
command for relevant pull requests and manual dispatch. It builds the three
Docker images sequentially with cached layers, explicitly initializes only the
isolated staging SQLite volume, and uploads reports/service diagnostics even
on failure. Its cleanup stops containers without deleting volumes; GitHub then
disposes of the ephemeral runner. No deployment secrets are required.

The runner refuses non-loopback service URLs. Defaults are
`http://127.0.0.1:18080` for the app and `http://127.0.0.1:18025` for Mailpit;
`STAGING_APP_URL` and `STAGING_MAIL_URL` may select other loopback ports.
Credentials match the disposable Docker seed, never a deployed account.

CI also runs `node tests/staging/check-startup.mjs` before acceptance. It requires
the actual login mount and reachable built JavaScript assets, because a PHP
router error can produce an HTTP 200 response without rendering the app.

The test logs into seeded admin, recipient, and outsider accounts using their
actual SMTP two-factor codes. It creates a unique
`library-run-<timestamp>@staging.invalid` uploader through an invitation, normal
registration, email verification, admin approval, and a fresh two-factor login.
Only that exact newly created non-admin account is permanently deleted. All
seeded identities, previous test data, volumes, generated fixtures, and local
artifacts remain reusable. The recipient's original storage limit is restored
in cleanup, including when an assertion fails. Unique run titles prevent an
existing item from becoming part of this run's removal batch. Failed runs may
leave their own disposable account/media for investigation; reruns use new IDs.

Coverage includes photo/video browser PUT uploads with real CORS, review gates,
reviewed photo display, video decoding and advancing playback time, explicit
sharing/revocation, durable Save after uploader purge, idempotent Save at the
quota limit, rejection of additional upload reservations, and bulk collection
add/move/removal. The run removes only its two saved references at the end and verifies a
previously playable signed HLS segment then returns 404. Video metadata includes
a unique run marker so a previous run cannot retain the same content hash.
Original fixture files are generated under `.fixtures/`; screenshots, traces,
HTML report, and run identifiers stay under ignored `artifacts/`. Local artifacts
remain on this machine. The CI workflow uploads its disposable reports and service
logs for seven days; these may contain test session cookies and signed loopback URLs.

Playback polling allows the application's normal two-minute negative HLS cache
and local FFmpeg processing to complete. The test timeout is six minutes.
Passing validates this local MinIO/transcoder contract, not production R2,
external transcoding infrastructure, email delivery, or hosting.

The **Real media staging acceptance** GitHub Actions workflow runs this same
stack on relevant pull requests and supports manual dispatch. It builds the
images sequentially with layer caching, initializes its isolated SQLite volume,
runs the acceptance journey, and stops services afterward. Prefer CI for the full
journey on a busy shared development machine.

After testing, stop idle containers without deleting reusable volumes:

```bash
docker compose -f docker/local/compose.yaml stop
```
