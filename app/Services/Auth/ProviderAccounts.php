<?php

namespace App\Services\Auth;

use App\Models\ProviderInvitation;
use App\Models\User;
use BWH\Auth\OAuth\OAuthIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds or creates the local account for a provider identity. Accounts are
 * bound by (provider, subject) and never matched by email: an existing local
 * account is linked only by an operator (`ops:bind-provider-account`).
 */
class ProviderAccounts
{
    public function resolve(OAuthIdentity $identity): User
    {
        try {
            // On MariaDB two first sign-ins for one subject take gap locks on
            // the missing rows and deadlock rather than meet the unique index;
            // a retry then finds the account the other request committed.
            return DB::transaction(fn (): User => $this->resolveOnce($identity), attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first sign-in created the account between our read
            // and insert; the binding it committed is the answer.
            $user = $this->bound($identity)->first();
            abort_if($user === null, 409, 'This sign-in could not be linked to an account. Please try again.');

            return $user;
        }
    }

    private function resolveOnce(OAuthIdentity $identity): User
    {
        /** @var User|null $user */
        $user = $this->bound($identity)->lockForUpdate()->first();
        abort_if($user?->trashed() === true, 403, 'This account has been deleted.');

        /** @var User|null $emailOwner */
        $emailOwner = User::withTrashed()->where('email', $identity->email)->lockForUpdate()->first();
        $addressTaken = $emailOwner !== null && $emailOwner->id !== $user?->id;

        if ($user !== null) {
            // The provider owns the address; the local names stay the person's own.
            // An address still held by another row (a deleted account, say) is
            // not adopted, but never locks out an account bound by subject.
            if ($user->email !== $identity->email && ! $addressTaken) {
                $user->forceFill(['email' => $identity->email, 'email_verified_at' => now()])->save();
            }

            return $user;
        }

        abort_if($addressTaken, 409, 'An account here already uses this email address. Ask an administrator to link it to your sign-in.');

        $user = new User;
        $user->name = Str::limit($identity->name, 255, '');
        $user->display_name = $user->name;
        $user->email = $identity->email;
        // Local credentials are never used under provider sign-in.
        $user->password = Str::random(64);
        $user->oauth_provider = $identity->provider;
        $user->oauth_subject = $identity->subject;
        $user->email_verified_at = now();
        // The provider admits people one application at a time, so reaching this
        // callback is the admission decision. The first account administers it.
        $user->approved_at = now();
        $user->is_admin = User::withTrashed()->doesntExist();
        $user->save();

        // Shown to whoever invited the address. The provider admits by link, not
        // address, so this is a courtesy and grants nothing.
        ProviderInvitation::query()->pendingTo($identity->provider, $identity->email)->update(['status' => ProviderInvitation::ACCEPTED]);

        return $user;
    }

    /** @return Builder<User> */
    private function bound(OAuthIdentity $identity): Builder
    {
        return User::withTrashed()
            ->where('oauth_provider', $identity->provider)
            ->where('oauth_subject', $identity->subject);
    }
}
