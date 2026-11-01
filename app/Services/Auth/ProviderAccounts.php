<?php

namespace App\Services\Auth;

use App\Models\User;
use BWH\Auth\OAuth\Login\RelyingPartyAccounts;
use BWH\Auth\OAuth\Login\SubjectBoundAccounts;
use BWH\Auth\OAuth\OAuthIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What differs for this application in how a provider identity maps to a local
 * account. The rules that pick the account (bound by provider and subject,
 * never by email; a collision is refused) are the package's
 * ({@see SubjectBoundAccounts}). An existing local
 * account is linked only by an operator (`oauth:bind-subject`).
 */
class ProviderAccounts extends RelyingPartyAccounts
{
    /**
     * Deleted accounts stay in view: a deleted account still owns its subject
     * and its address, so neither is handed to anyone else.
     *
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        return User::withTrashed();
    }

    /** @return array<string, mixed> */
    public function newAccountAttributes(OAuthIdentity $identity): array
    {
        $name = Str::limit($identity->name, 255, '');

        return [
            'name' => $name,
            'display_name' => $name,
            'email' => $identity->email,
            // Local credentials are never used under provider sign-in.
            'password' => Str::random(64),
            'email_verified_at' => now(),
            // The provider admits people one application at a time, so reaching the
            // callback is the admission decision. The first account administers it.
            'approved_at' => now(),
            'is_admin' => ! User::withTrashed()->exists(),
        ];
    }

    /**
     * The provider owns the address; the local names stay the person's own. An
     * address still held by another row (a deleted account, say) is not
     * adopted, but never locks out an account bound by subject. That check is
     * not under lock: the unique index is the race-free guard.
     *
     * @return array<string, mixed>
     */
    public function refreshedAttributes(Model $account, OAuthIdentity $identity): array
    {
        if ($account->getAttribute('email') === $identity->email) {
            return [];
        }

        $held = User::withTrashed()->where('email', $identity->email)->whereKeyNot($account->getKey())->exists();

        return $held ? [] : ['email' => $identity->email, 'email_verified_at' => now()];
    }

    /** Only the address is refreshed, so it is all a concurrent sign-in must agree on. */
    public function assertsSameProfile(Model $account, OAuthIdentity $identity): bool
    {
        return strcasecmp((string) $account->getAttribute('email'), $identity->email) === 0;
    }
}
