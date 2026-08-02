# Vora documentation

Feature- and subsystem-oriented docs for developers working on Vora. This set
will grow over time; keep each document focused on one area.

`AGENTS.md` and `TESTING.AGENTS.md` in the repo root remain the binding agent
contract and validation contract. These docs explain the *why* behind the rules
and the invariants specific features must uphold; they do not override those two.

> **Never** commit secrets, credentials, or concrete bucket names. Refer to
> storage by its role and to configuration by env var name. Real values live in
> `.env` only.

## Conventions

Cross-cutting rules that apply to every feature. Read these before designing a
new surface.

- [Privacy and visibility](conventions/privacy-and-visibility.md) — audience
  semantics, clamping, in-query filtering, block asymmetry, neutral failure
- [Moderation and account states](conventions/moderation.md) —
  publish-immediately, the distinct removal paths, ban/disable/restriction
  states, self-service data access
- [Schema and query portability](conventions/schema-portability.md) —
  SQLite + MariaDB rules, nullable-unique instead of partial indexes, keyset
  pagination indexes, FK delete behaviour

## Social

- [Posts, discussions, and Interest contexts](social/discussions.md)

## Frontend

- [Polling and freshness](frontend/polling-and-freshness.md) — the adaptive
  polling hook and the ETag/revision contract

## Media

User-uploaded photos and videos with admin review and privacy controls.

- [Overview](media/overview.md)
- [Storage and buckets](media/storage-and-buckets.md)
- [s3-hls video integration (app contract)](media/s3-hls-integration.md)
- [Moderation and privacy](media/moderation-and-privacy.md)

See also [s3-hls-integration.md](s3-hls-integration.md) for the infrastructure
overview (buckets, token scopes, transcoder host).

## UX

Recommendation documents; these record reasoning, not current state.

- [IA redesign](ux/ia-redesign-recommendation.md)
- [Profile personas](ux/profile-personas-recommendation.md)
