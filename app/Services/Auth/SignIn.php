<?php

namespace App\Services\Auth;

use BWH\Auth\OAuth\OAuthClient;

/**
 * How people sign in to this installation: locally (passwords, email codes and
 * passkeys checked here) or through an OAuth identity provider that owns those
 * credentials. Each installation chooses with AUTH_SIGN_IN.
 */
class SignIn
{
    public const LOCAL = 'local';

    public const PROVIDER = 'provider';

    public const EMBEDDED = 'embedded';

    public static function mode(): string
    {
        $mode = config('auth.sign_in');

        return in_array($mode, [self::PROVIDER, self::EMBEDDED], true) ? $mode : self::LOCAL;
    }

    public static function usesProvider(): bool
    {
        return self::mode() !== self::LOCAL;
    }

    /** The provider name every account binding is stored under. */
    public static function provider(): string
    {
        return (string) config('bherila-auth.oauth_client.provider');
    }

    /** Where people manage their password and passkeys, when the provider owns them. */
    public static function accountSettingsUrl(): ?string
    {
        if (! self::usesProvider() || ! OAuthClient::isConfigured()) {
            return null;
        }

        return rtrim((string) config('bherila-auth.oauth_client.base_url'), '/').'/settings';
    }
}
