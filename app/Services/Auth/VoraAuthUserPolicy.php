<?php

namespace App\Services\Auth;

use App\Models\User;
use BWH\Auth\Contracts\AuthUserPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * App-specific auth policy consumed by the bherila/auth-laravel package: decides
 * whether a user may complete a passkey login and where users land afterward.
 */
class VoraAuthUserPolicy implements AuthUserPolicy
{
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

        return $request->session()->pull('url.intended') ?: $user->getLoginRedirectUrl();
    }
}
