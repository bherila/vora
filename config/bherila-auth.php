<?php

use App\Models\User;

// With provider sign-in the identity provider owns passwords, passkeys and
// recovery, so the package's local credential endpoints are not registered.
$localCredentials = env('AUTH_SIGN_IN', 'local') !== 'provider';

return [
    'routes' => [
        'enabled' => true,
        'prefix' => 'api',
        'middleware' => ['web'],
        'passkeys' => $localCredentials,
        'password_resets' => $localCredentials,
        'change_password' => $localCredentials,
        'two_factor' => $localCredentials,
    ],

    // Provider sign-in (AUTH_SIGN_IN=provider). OAUTH_PROVIDER names the provider
    // in every account binding: choose it once per provider and never change it.
    'oauth_client' => [
        'provider' => env('OAUTH_PROVIDER', 'identity'),
        'base_url' => env('OAUTH_PROVIDER_URL', ''),
        'client_id' => env('OAUTH_CLIENT_ID'),
        'client_secret' => env('OAUTH_CLIENT_SECRET'),
        'redirect_uri' => env('OAUTH_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/oauth/callback'),
        'scope' => env('OAUTH_SCOPE', 'identity:read'),
        'authorize_path' => '/oauth/authorize',
        'token_path' => '/oauth/token',
        'identity_path' => '/api/oauth/user',
        'end_session_path' => '/oauth/end-session',
        // The provider's exact issuer (defaults to base_url). Set
        // OAUTH_AUTHORIZATION_RESPONSE_ISS=required once the provider sends RFC 9207 `iss`.
        'issuer' => env('OAUTH_PROVIDER_ISSUER'),
        'authorization_response_iss' => env('OAUTH_AUTHORIZATION_RESPONSE_ISS', 'when_present'),
        'pending_flow_ttl' => 1800,
        'pending_flow_cache_store' => env('BHERILA_AUTH_OAUTH_PENDING_FLOW_CACHE_STORE'),
        'metadata_cache_seconds' => 3600,
        'servers' => [],
    ],

    // Ends a signed-in session once the provider reports the identity disabled,
    // deleted, reset or no longer granted this application. Enable only after the
    // provider's identity-status endpoint is reachable with this client.
    'provider_identity' => [
        'enabled' => (bool) env('BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED', false),
        'cache_store' => env('BHERILA_AUTH_PROVIDER_IDENTITY_CACHE_STORE'),
        'binding' => [
            'provider_column' => 'oauth_provider',
            'subject_column' => 'oauth_subject',
        ],
        'expired_redirect_route' => 'home',
        'except_routes' => ['logout'],
        'bearer_guard' => 'api',
    ],

    // Invitations people send from this installation through the provider
    // (provider sign-in only). The provider must enable this installation and
    // pin the public half of the key below; see README.
    'application_invitations' => [
        'issuer' => env('BHERILA_AUTH_INVITATION_ISSUER'),
        'key_id' => env('BHERILA_AUTH_INVITATION_KEY_ID'),
        'private_key_path' => env('BHERILA_AUTH_INVITATION_PRIVATE_KEY_PATH'),
        'private_key' => env('BHERILA_AUTH_INVITATION_PRIVATE_KEY'),
        'private_key_passphrase' => env('BHERILA_AUTH_INVITATION_PRIVATE_KEY_PASSPHRASE'),
        'timeout_seconds' => (int) env('BHERILA_AUTH_INVITATION_TIMEOUT_SECONDS', 10),
        'token_cache_store' => env('BHERILA_AUTH_INVITATION_TOKEN_CACHE_STORE'),
    ],

    'migrations' => [
        'drop_tables_on_rollback' => false,
    ],

    'audit' => [
        // 'null' discards events (default); 'database' persists them to the audit table.
        'driver' => env('BHERILA_AUTH_AUDIT_DRIVER', 'database'),
        'table' => 'auth_audit_log',
        // Expose the package's read endpoints (own login history + admin list).
        'routes_enabled' => env('BHERILA_AUTH_AUDIT_ROUTES', true),
        // null = retain forever (no pruning). Set a positive integer to enable `model:prune`.
        'retention_days' => env('BHERILA_AUTH_AUDIT_RETENTION_DAYS'),
        // Gate ability required for the cross-user admin endpoint; null disables that route.
        'admin_ability' => env('BHERILA_AUTH_AUDIT_ADMIN_ABILITY', 'admin-only'),
    ],

    'throttle' => [
        // Opt-in brute-force lockout backed by auth_audit_log rows. Disabled by default.
        'enabled' => env('BHERILA_AUTH_THROTTLE_ENABLED', true),
        'max_attempts' => env('BHERILA_AUTH_THROTTLE_MAX_ATTEMPTS', 5),
        'decay_minutes' => env('BHERILA_AUTH_THROTTLE_DECAY_MINUTES', 15),
        // How failed attempts are grouped into a lockout key:
        //   'email'    — per account: count an email's failures across all source IPs
        //   'ip'       — per source: count an IP's failures across all emails
        //   'email_ip' — per account+source pair (most conservative; default)
        // Any other value falls back to 'email_ip'.
        'key' => env('BHERILA_AUTH_THROTTLE_KEY', 'email_ip'),
        'record_blocked' => env('BHERILA_AUTH_THROTTLE_RECORD_BLOCKED', true),
    ],

    'password_resets' => [
        'reset_url' => env('BHERILA_AUTH_PASSWORD_RESET_URL', env('APP_URL', '').'/reset-password/{token}?email={email}'),
        'request_url' => env('BHERILA_AUTH_PASSWORD_REQUEST_URL', '/forgot-password'),
        'redirect_after_reset' => env('BHERILA_AUTH_PASSWORD_RESET_REDIRECT', '/'),
        'mail_subject' => env('BHERILA_AUTH_PASSWORD_RESET_MAIL_SUBJECT', 'Reset your :app password'),
        'notice_subject' => env('BHERILA_AUTH_PASSWORD_NOTICE_MAIL_SUBJECT', 'Your :app password was changed'),
        'verify_email_on_reset' => false,
    ],

    'two_factor' => [
        'table' => 'auth_two_factor_attempts',
        'expires_minutes' => 15,
        'allow_test_code' => env('BHERILA_AUTH_ALLOW_TEST_2FA_CODE', false),
        'test_code_environments' => ['local', 'testing'],
        'test_code' => '999999',
        'mail_subject' => env('BHERILA_AUTH_TWO_FACTOR_MAIL_SUBJECT', 'Verify your login - :app'),
        'login_url' => env('BHERILA_AUTH_LOGIN_URL', '/login'),
        'session_user_key' => 'bherila_auth_2fa_user_id',
        'session_remember_key' => 'bherila_auth_2fa_remember',
    ],

    'passkeys' => [
        'table' => 'auth_passkeys',
        'rp_name' => env('WEBAUTHN_RP_NAME', env('APP_NAME', 'App')),
        'allowed_origins' => array_filter(array_map('trim', explode(',', env('WEBAUTHN_ALLOWED_ORIGINS', '')))),
        'timeout' => 60000,
        'resident_key' => env('WEBAUTHN_RESIDENT_KEY', 'preferred'),
        'user_verification' => env('WEBAUTHN_USER_VERIFICATION', 'preferred'),
    ],

    'users' => [
        'model' => config('auth.providers.users.model', User::class),
        'name_attribute' => 'name',
        'email_attribute' => 'email',
        'force_change_password_attribute' => null,
    ],
];
