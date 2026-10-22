<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Events\EventJoining;
use BWH\Auth\Contracts\AuthUserPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * App-specific auth policy consumed by the bherila/auth-laravel package: decides
 * whether a user may complete a passkey login and where users land afterward.
 */
class VoraAuthUserPolicy implements AuthUserPolicy
{
    public function canLogin(Authenticatable $user, Request $request): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->canLogin();
    }

    public function canPasskeyLogin(Authenticatable $user, Request $request): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->canLogin() && $user->hasVerifiedEmail();
    }

    public function redirectAfterLogin(Authenticatable $user, Request $request): string
    {
        if (! $user instanceof User) {
            return '/login';
        }

        if (! $user->hasVerifiedEmail()) {
            return route('verification.notice', [], false);
        }

        if ($user->isPendingApproval()) {
            return route('approval.pending', [], false);
        }

        if ($user->force_change_pw) {
            return route('user.settings', [], false);
        }

        return app(EventJoining::class)->url($request)
            ?? $this->safeIntended($request)
            ?? $user->getLoginRedirectUrl();
    }

    private function safeIntended(Request $request): ?string
    {
        $url = $request->session()->pull('url.intended');
        if (! is_string($url) || $url === '') {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['host']) || isset($parts['scheme'])) {
            if (($parts['scheme'] ?? null) !== $request->getScheme()
                || strtolower($parts['host'] ?? '') !== strtolower($request->getHost())
                || ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) !== $request->getPort()) {
                return null;
            }
        }
        $local = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
        $decoded = rawurldecode($local);
        if (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//')
            || preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
            return null;
        }

        return $local;
    }
}
