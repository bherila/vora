<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Events\EventJoining;
use BWH\Auth\Contracts\AuthUserPolicy;
use BWH\Auth\Services\DefaultAuthUserPolicy;
use BWH\Auth\Support\IntendedUrl;
use BWH\AuthManager\Embedded\EmbeddedContext;
use BWH\AuthManager\Models\User as ProviderUser;
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
        if ($this->isProviderUser($user)) {
            return app(DefaultAuthUserPolicy::class)->canLogin($user, $request);
        }

        if (! $user instanceof User) {
            return false;
        }

        if ($request->routeIs('emergency.*')) {
            return app(EmergencyAccess::class)->eligible($user) !== null;
        }

        // A deleted account stays bound to its subject but cannot sign in.
        return ! $user->trashed() && $user->canLogin();
    }

    public function canPasskeyLogin(Authenticatable $user, Request $request): bool
    {
        if ($this->isProviderUser($user)) {
            return app(DefaultAuthUserPolicy::class)->canPasskeyLogin($user, $request);
        }

        if (! $user instanceof User) {
            return false;
        }

        return $user->canLogin() && $user->hasVerifiedEmail();
    }

    public function redirectAfterLogin(Authenticatable $user, Request $request): string
    {
        if ($this->isProviderUser($user)) {
            return app(DefaultAuthUserPolicy::class)->redirectAfterLogin($user, $request);
        }

        if (! $user instanceof User) {
            return '/login';
        }

        if (! $user->hasVerifiedEmail()) {
            return route('verification.notice', [], false);
        }

        if ($user->isPendingApproval()) {
            return route('approval.pending', [], false);
        }

        // The identity provider owns passwords under provider sign-in.
        if ($user->force_change_pw && ! SignIn::usesProvider()) {
            return route('user.settings', [], false);
        }

        if (app(AgeEligibility::class)->reason($user) !== null) {
            return route('age.eligibility', [], false);
        }

        return app(EventJoining::class)->url($request)
            ?? IntendedUrl::consume($request, absolute: false, rejectEncodedUnsafe: true)
            ?? $user->getLoginRedirectUrl();
    }

    /** Shared credential controllers resolve this policy before their context middleware. */
    private function isProviderUser(Authenticatable $user): bool
    {
        return SignIn::mode() === SignIn::EMBEDDED
            && $user instanceof ProviderUser
            && app(EmbeddedContext::class)->active();
    }
}
