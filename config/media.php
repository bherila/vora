<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage disks per media type
    |--------------------------------------------------------------------------
    |
    | Each media type is stored on its own filesystem disk (configured in
    | config/filesystems.php). Videos go to the transcoder's source bucket;
    | photos go to a separate bucket the transcoder never scans. Bucket names
    | and credentials live in the environment, never here.
    |
    */

    'disks' => [
        'photo' => 'photos',
        'video' => 's3',
    ],

    // Disk that holds the s3-hls transcoder output (read-only for the app).
    'hls_disk' => 'hls',

    // Disk for client-generated thumbnails/posters. Defaults to the photo disk
    // because the video transcoder never scans it and it already accepts image
    // objects. Video posters live here too, never on the video (source) disk.
    'thumbnail_disk' => env('MEDIA_THUMBNAIL_DISK', 'photos'),

    // Client-generated thumbnails/posters are a separate presigned PUT, so their
    // size is enforced on completion (not bounded by the source's type limit).
    // A thumbnail that exceeds this is discarded rather than retained/served.
    'thumbnail' => [
        'max_bytes' => (int) env('MEDIA_THUMBNAIL_MAX_BYTES', 5 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Object key prefix
    |--------------------------------------------------------------------------
    |
    | All uploads are stored under this prefix as "<prefix>/<user-id>/<ulid>.<ext>".
    | The transcoder scans the whole source bucket, so the prefix mainly keeps
    | uploads grouped and predictable.
    |
    */

    'key_prefix' => env('MEDIA_KEY_PREFIX', 'uploads'),

    /*
    |--------------------------------------------------------------------------
    | Per-type upload constraints
    |--------------------------------------------------------------------------
    |
    | Maximum upload size (bytes) and the MIME types accepted for each media
    | type. Enforced server-side when issuing the presigned URL and again on
    | upload completion.
    |
    */

    'photo' => [
        'max_bytes' => (int) env('MEDIA_PHOTO_MAX_BYTES', 25 * 1024 * 1024),
        'mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/avif',
        ],
    ],

    'video' => [
        'max_bytes' => (int) env('MEDIA_VIDEO_MAX_BYTES', 5 * 1024 * 1024 * 1024),
        'mime_types' => [
            'video/mp4',
            'video/quicktime',
            'video/webm',
            'video/x-matroska',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed URL lifetimes (minutes)
    |--------------------------------------------------------------------------
    */

    'upload_url_ttl' => (int) env('MEDIA_UPLOAD_URL_TTL', 30),
    'view_url_ttl' => (int) env('MEDIA_VIEW_URL_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Multipart upload policy
    |--------------------------------------------------------------------------
    |
    | Large videos can use presigned S3/R2 multipart uploads for retry/resume.
    | The app aborts sessions when the user cancels and prunes stale pending rows,
    | but storage should also enforce an AbortIncompleteMultipartUpload lifecycle
    | rule after MEDIA_MULTIPART_INCOMPLETE_RETENTION_DAYS as a backstop.
    |
    */

    'multipart' => [
        'enabled' => filter_var(env('MEDIA_MULTIPART_UPLOADS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'threshold_bytes' => (int) env('MEDIA_MULTIPART_THRESHOLD_BYTES', 100 * 1024 * 1024),
        'part_size_bytes' => (int) env('MEDIA_MULTIPART_PART_SIZE_BYTES', 16 * 1024 * 1024),
        'url_ttl' => (int) env('MEDIA_MULTIPART_URL_TTL', 30),
        'max_parts' => 10_000,
        'incomplete_retention_days' => (int) env('MEDIA_MULTIPART_INCOMPLETE_RETENTION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | List pagination
    |--------------------------------------------------------------------------
    |
    | Page size for the user library and admin review queue list endpoints.
    |
    */

    'page_size' => (int) env('MEDIA_PAGE_SIZE', 24),

];
