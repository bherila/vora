<?php

namespace App\Services\Auth;

use App\Models\ProviderInvitation;
use App\Models\User;
use App\Services\InviteService;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationConflict;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationError;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationException;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationNotFound;
use BWH\Auth\OAuth\Invitations\ApplicationInvitations;
use BWH\Auth\OAuth\Invitations\IssuedInvitation;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Asks the identity provider to invite people to this installation, under
 * provider sign-in. The provider sends the email and admits the person to this
 * installation's sign-in client; their first sign-in creates an approved
 * account here. Who may invite is decided here: administrators, and anyone
 * spending an invite from a balance an administrator issued.
 */
class ProviderInvitationService
{
    public function __construct(
        private readonly ApplicationInvitations $provider,
        private readonly InviteService $invites,
    ) {}

    public function canInvite(User $user): bool
    {
        return $user->isAdmin() || $this->invites->availableBalance($user) > 0;
    }

    public function canManage(User $user, ProviderInvitation $invitation): bool
    {
        return $user->isAdmin() || $invitation->inviter_user_id === $user->id;
    }

    /**
     * Invite an address. Anyone but an administrator spends one invite, and
     * only once the provider has created the invitation.
     *
     * @return array{0: ProviderInvitation, 1: IssuedInvitation}
     *
     * @throws RuntimeException when the actor has no invites available
     * @throws ApplicationInvitationException
     */
    public function create(User $actor, string $email): array
    {
        $subject = $this->subject($actor);

        return DB::transaction(function () use ($actor, $subject, $email): array {
            $grant = null;
            if (! $actor->isAdmin()) {
                // Held until the invitation is recorded, so concurrent requests
                // cannot spend the same invite twice.
                $grant = $this->invites->lockNextGrant($actor) ?? throw new RuntimeException('You have no invites available.');
            }

            $issued = $this->call(fn (): IssuedInvitation => $this->provider->create($subject, $email));
            $grant?->decrement('remaining');

            // The provider replaced this installation's pending invitation to the address.
            ProviderInvitation::query()->pendingTo(SignIn::provider(), $email)->update(['status' => ProviderInvitation::SUPERSEDED]);
            $invitation = ProviderInvitation::create([
                'oauth_provider' => SignIn::provider(),
                'provider_invitation_id' => $issued->id,
                'inviter_user_id' => $actor->id,
                'invite_grant_id' => $grant?->id,
                'email' => $email,
                'status' => ProviderInvitation::PENDING,
                'expires_at' => $issued->expiresAt,
                'email_sent' => $issued->emailSent,
            ]);

            return [$invitation, $issued];
        });
    }

    /**
     * Send a pending or expired invitation again, with a new link and expiry.
     *
     * @throws ApplicationInvitationException
     */
    public function resend(User $actor, ProviderInvitation $invitation): IssuedInvitation
    {
        $subject = $this->subject($actor);
        try {
            $issued = $this->call(fn (): IssuedInvitation => $this->provider->resend($subject, $invitation->provider_invitation_id));
        } catch (ApplicationInvitationConflict|ApplicationInvitationNotFound $refused) {
            $invitation->update(['status' => $refused->error === ApplicationInvitationError::InvitationAccepted
                ? ProviderInvitation::ACCEPTED
                : ProviderInvitation::CLOSED]);
            throw $refused;
        }

        $invitation->update(['expires_at' => $issued->expiresAt, 'email_sent' => $issued->emailSent]);

        return $issued;
    }

    /**
     * Revoke an invitation so its link stops working. The invite it spent is not returned.
     *
     * @throws ApplicationInvitationException
     */
    public function revoke(User $actor, ProviderInvitation $invitation): void
    {
        $subject = $this->subject($actor);
        try {
            $this->call(fn () => $this->provider->revoke($subject, $invitation->provider_invitation_id));
        } catch (ApplicationInvitationConflict|ApplicationInvitationNotFound $refused) {
            $invitation->update(['status' => $refused->error === ApplicationInvitationError::InvitationAccepted
                ? ProviderInvitation::ACCEPTED
                : ProviderInvitation::CLOSED]);
            throw $refused;
        }

        $invitation->update(['status' => ProviderInvitation::REVOKED]);
    }

    /**
     * The provider names the person asking by the subject this installation is
     * bound to. An account bound elsewhere, or not at all, cannot ask.
     *
     * @throws ApplicationInvitationException
     */
    private function subject(User $actor): string
    {
        if ($actor->oauth_provider !== SignIn::provider() || ! is_string($actor->oauth_subject) || $actor->oauth_subject === '') {
            throw ApplicationInvitationException::for(ApplicationInvitationError::ActorNotEligible);
        }

        return $actor->oauth_subject;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $request
     * @return T
     *
     * @throws ApplicationInvitationException
     */
    private function call(Closure $request): mixed
    {
        try {
            return $request();
        } catch (InvalidArgumentException) {
            // A subject not in the provider's form cannot act at the provider.
            throw ApplicationInvitationException::for(ApplicationInvitationError::ActorNotEligible);
        }
    }
}
