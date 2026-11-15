<?php

namespace App\Services\Account;

use App\Mail\ConfirmEmailChangeMail;
use App\Mail\EmailChangeRequestedMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Changes a verified account's email only after the new address proves it
 * receives mail: the request parks the address in `pending_email`, mails a
 * signed link to it and tells the current address. An address that was never
 * verified protects nothing, so it is replaced directly and re-verified.
 */
class EmailChangeService
{
    public const PENDING = 'pending';

    public const REPLACED = 'replaced';

    /** @return self::PENDING|self::REPLACED */
    public function request(User $user, string $newEmail): string
    {
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill([
                'email' => $newEmail,
                'email_verified_at' => null,
                'pending_email' => null,
                'pending_email_requested_at' => null,
            ])->save();
            $user->sendEmailVerificationNotification();

            return self::REPLACED;
        }

        $user->forceFill([
            'pending_email' => $newEmail,
            'pending_email_requested_at' => now(),
        ])->save();

        Mail::to($newEmail)->send(new ConfirmEmailChangeMail($this->confirmationUrl($user, $newEmail)));
        Mail::to($user->email)->send(new EmailChangeRequestedMail($newEmail));

        return self::PENDING;
    }

    public function confirmationUrl(User $user, string $newEmail): string
    {
        return URL::temporarySignedRoute(
            'account.email.confirm',
            now()->addMinutes($this->lifetimeMinutes()),
            ['id' => $user->getKey(), 'hash' => sha1(mb_strtolower($newEmail))],
        );
    }

    /** The pending address while its confirmation link can still be used. */
    public function pendingEmail(User $user): ?string
    {
        if ($user->pending_email === null || $user->pending_email_requested_at === null) {
            return null;
        }

        return $user->pending_email_requested_at->greaterThan(now()->subMinutes($this->lifetimeMinutes()))
            ? $user->pending_email
            : null;
    }

    /**
     * Apply the pending address if the link names it and nothing has since made
     * the change invalid (a newer request, a lock, a provider binding, or the
     * address being taken by another account).
     */
    public function confirm(User $user, string $hash): bool
    {
        return DB::transaction(function () use ($user, $hash): bool {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $pending = $locked->pending_email;

            if ($pending === null || ! hash_equals(sha1(mb_strtolower($pending)), $hash)) {
                return false;
            }

            $taken = User::query()->where('email', $pending)->whereKeyNot($locked->getKey())->exists();
            if ($taken || $locked->email_locked || $locked->oauth_subject !== null) {
                $locked->forceFill(['pending_email' => null, 'pending_email_requested_at' => null])->save();

                return false;
            }

            $locked->forceFill([
                'email' => $pending,
                'email_verified_at' => now(),
                'pending_email' => null,
                'pending_email_requested_at' => null,
            ])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    private function lifetimeMinutes(): int
    {
        return (int) config('auth.verification.expire', 60);
    }
}
