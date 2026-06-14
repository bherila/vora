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

];
