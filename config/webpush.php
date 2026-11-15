<?php

use App\Models\PushSubscription;

return [

    /**
     * These are the keys for authentication (VAPID).
     * These keys must be safely stored and should not change.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /**
     * This is model that will be used to for push subscriptions.
     */
    'model' => PushSubscription::class,

    /**
     * This is the name of the table that will be created by the migration and
     * used by the PushSubscription model shipped with this package.
     */
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    /**
     * This is the database connection that will be used by the migration and
     * the PushSubscription model shipped with this package.
     */
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /**
     * The Guzzle client options used by Minishlink\WebPush.
     */
    'client_options' => [],

    /**
     * The automatic padding in bytes used by Minishlink\WebPush.
     * Set to false to support Firefox Android with v1 endpoint.
     */
    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

    /**
     * Push services a subscription endpoint may point at. Browsers only hand
     * out endpoints on their vendor's service, so anything else would only make
     * the app send requests to a host of the subscriber's choosing. An entry
     * starting with a dot matches that domain's subdomains. Add hosts with a
     * comma-separated WEBPUSH_EXTRA_ENDPOINT_HOSTS.
     */
    'endpoint_hosts' => array_values(array_filter(array_merge([
        'fcm.googleapis.com',               // Chrome, Edge on Android, Opera, Samsung Internet
        'android.googleapis.com',           // older Chrome endpoints
        'updates.push.services.mozilla.com', // Firefox (autopush)
        'push.services.mozilla.com',
        'web.push.apple.com',               // Safari and iOS home-screen apps
        '.push.apple.com',
        '.notify.windows.com',              // Edge on Windows (WNS)
    ], array_map('trim', explode(',', (string) env('WEBPUSH_EXTRA_ENDPOINT_HOSTS', '')))))),

    /**
     * Subscriptions kept per account; registering another drops the oldest.
     */
    'max_subscriptions_per_user' => (int) env('WEBPUSH_MAX_SUBSCRIPTIONS_PER_USER', 10),

];
