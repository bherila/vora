# Vora documentation

Feature- and subsystem-oriented docs for developers working on Vora. This set
will grow over time; keep each document focused on one area.

> **Never** commit secrets, credentials, or concrete bucket names. Refer to
> storage by its role and to configuration by env var name. Real values live in
> `.env` only.

## Media

User-uploaded photos and videos with admin review and privacy controls.

- [Overview](media/overview.md)
- [Storage and buckets](media/storage-and-buckets.md)
- [s3-hls video integration (app contract)](media/s3-hls-integration.md)
- [Moderation and privacy](media/moderation-and-privacy.md)

See also [s3-hls-integration.md](s3-hls-integration.md) for the infrastructure
overview (buckets, token scopes, transcoder host).
