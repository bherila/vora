# Reusable local development and staging

This stack runs the normal Laravel HTTP app with built frontend assets, its own
SQLite volume, private MinIO buckets, a real FFmpeg HLS worker, a scheduler/queue
worker, and Mailpit. It uses no production credentials or host application data.
Every published port binds to loopback. Fixed credentials below are exclusively
for this disposable local stack; never expose it to a network or use these in a
deployed environment.

Runtime CPU/memory limits keep the app, object store, and transcoder to one CPU
and 1 GiB each, with smaller limits for mail and the scheduler. Keep the stack
stopped when idle. Image compilation and repository validation are separate
workloads; avoid running builds, full test suites, and transcoding concurrently
on a busy workstation. CI remains the place for full validation gates.

From the repository root:

```bash
docker compose -f docker/local/compose.yaml build
docker compose -f docker/local/compose.yaml up -d
```

These commands do **not** migrate or seed the database. The app/scheduler wait
until initialization is explicitly requested. After authorization to initialize
this dedicated SQLite volume, run:

```bash
docker compose -f docker/local/compose.yaml exec app sh docker/local/initialize.sh --initialize-sqlite
```

Initialization refuses any environment other than explicitly enabled staging,
any non-SQLite connection, and any database path outside the fixed container
volume path. Seeding also refuses an existing account database. Re-running
initialization applies pending SQLite migrations and preserves existing users.

- App: <http://127.0.0.1:18080>
- Mailpit: <http://127.0.0.1:18025> (read the real email two-factor code here)
- MinIO console: <http://127.0.0.1:19001>
- S3 API: <http://127.0.0.1:19000>

Accounts are `admin@staging.invalid`, `uploader@staging.invalid`,
`recipient@staging.invalid`, and `outsider@staging.invalid`. Their password is
`Local-Staging-Only-2026!`. All are approved and verified; only admin is an admin.
Library is enabled. Auth, CSRF, moderation, sharing, and email two-factor checks
run normally. MinIO login is `vora-local-staging` /
`disposable-storage-password`. The application key and storage credentials in
Compose are intentionally disposable and contain no production secrets.

MinIO and the transcoder share the app's network namespace and use port 19000
both inside and outside Docker. Presigned PUT/segment URLs therefore use the
same reachable host/port for PHP and the browser without DNS or hosts-file edits.
The scheduler also shares this namespace for storage cleanup operations.

The worker polls reviewed video keys every two seconds, transcodes a single real
H.264/AAC rendition with fMP4 segments, and writes the production-style
`mappings/<reviewed-key>.json` and `by-id/sha256:<hash>/master.m3u8` layout. Like
the real transcoder's cleanup, it then deletes mappings whose source is gone and
any `by-id/` tree no remaining mapping uses. It is
a small local contract implementation; passing it does not validate the deployed
external transcoder, Cloudflare R2 permissions, or production hosting.

MinIO is built from the pinned upstream source commit for
`RELEASE.2025-09-07T16-13-09Z`, avoiding a dependency on historical prebuilt image
availability. The first build downloads Go modules and compiles the
server; subsequent builds reuse Docker layers.

Follow [the Library rollout procedure](../../docs/media/library-rollout.md) using
these disposable accounts. Run the read-only diagnostic inside the app container
with the four test ULIDs. Use only these accounts for destructive lifecycle
checks. Logs are available through `docker compose ... logs app transcoder mail`.

Stop services when idle with:

```bash
docker compose -f docker/local/compose.yaml stop
```

Resume the same accounts, database, and media later with:

```bash
docker compose -f docker/local/compose.yaml start
```

For code changes, rebuild assets/application and recreate containers while
preserving the named volumes:

```bash
docker compose -f docker/local/compose.yaml down
docker compose -f docker/local/compose.yaml build
docker compose -f docker/local/compose.yaml up -d
```

The setup uses built frontend assets, so code edits require a rebuild. Database
schema updates remain a separate explicit initialization invocation; restarting
the app never applies migrations automatically. Mailpit mail is ephemeral, while
database, app storage, and object storage persist across stop/start and down/up.
Keep these local volumes and images for future sessions unless a clean reset is
specifically requested.

To discard **all local staging accounts/media**, explicitly use
`docker compose -f docker/local/compose.yaml down --volumes`. Volumes are scoped
to the `vora-local-staging` project; this command must not target another stack.
