<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreProviderInvitationRequest;
use App\Models\ProviderInvitation;
use App\Models\User;
use App\Services\Auth\ProviderInvitationService;
use App\Services\Auth\SignIn;
use App\Services\InviteService;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationActorNotEligible;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationConflict;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationError;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationException;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationInvalid;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationMisconfigured;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationNotFound;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationRateLimited;
use BWH\Auth\OAuth\Invitations\IssuedInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Email invitations sent through the identity provider, under provider sign-in.
 *
 * The acceptance link the provider returns is a bearer credential: it is shown
 * once, to the person who asked, and never stored or logged here.
 */
class ProviderInvitationController extends Controller
{
    /** Most recent invitations listed; older ones stay at the provider. */
    private const LIST_LIMIT = 200;

    public function __construct(
        private readonly ProviderInvitationService $invitations,
        private readonly InviteService $invites,
    ) {}

    public function page(Request $request): View
    {
        return view('user.provider-invitations', ['initialData' => [
            'invitations' => $this->payload($request->user()),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($request->user())]);
    }

    public function store(StoreProviderInvitationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $this->invitations->canInvite($user)) {
            return $this->failure(403, ProviderInvitationService::NO_INVITES);
        }

        try {
            [$invitation, $issued] = $this->invitations->create($user, $request->validated('email'));
        } catch (ApplicationInvitationException $refused) {
            return $this->refused($refused);
        }

        return $this->issued($user, $invitation, $issued, 201);
    }

    public function resend(Request $request, ProviderInvitation $providerInvitation): JsonResponse
    {
        if (($refusal = $this->unmanageable($request->user(), $providerInvitation)) !== null) {
            return $refusal;
        }

        try {
            $issued = $this->invitations->resend($request->user(), $providerInvitation);
        } catch (ApplicationInvitationException $refused) {
            return $this->refused($refused);
        }

        return $this->issued($request->user(), $providerInvitation, $issued, 200);
    }

    public function revoke(Request $request, ProviderInvitation $providerInvitation): JsonResponse
    {
        if (($refusal = $this->unmanageable($request->user(), $providerInvitation)) !== null) {
            return $refusal;
        }

        try {
            $this->invitations->revoke($request->user(), $providerInvitation);
        } catch (ApplicationInvitationException $refused) {
            return $this->refused($refused);
        }

        return response()->json(['success' => true, 'data' => ['invitation' => $this->present($providerInvitation->refresh(), $request->user()->isAdmin())]]);
    }

    /** Only an invitation's sender or an administrator may act on it, and only while it is pending. */
    private function unmanageable(User $user, ProviderInvitation $invitation): ?JsonResponse
    {
        if ($invitation->oauth_provider !== SignIn::provider() || ! $this->invitations->canManage($user, $invitation)) {
            return $this->failure(404, 'Invitation not found.');
        }
        if ($invitation->status !== ProviderInvitation::PENDING) {
            return $this->failure(409, 'This invitation is no longer pending.');
        }

        return null;
    }

    private function issued(User $viewer, ProviderInvitation $invitation, IssuedInvitation $issued, int $status): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'invitation' => $this->present($invitation->refresh(), $viewer->isAdmin()),
            'link' => $issued->link(),
        ]], $status)->header('Cache-Control', 'no-store');
    }

    private function refused(ApplicationInvitationException $refused): JsonResponse
    {
        [$status, $message] = match (true) {
            $refused instanceof ApplicationInvitationInvalid => [422, $refused->errors['email'][0] ?? 'Enter a valid email address.'],
            $refused instanceof ApplicationInvitationRateLimited => [429, 'Too many invitations for now. Try again later.'],
            $refused instanceof ApplicationInvitationActorNotEligible => [403, 'Your sign-in account cannot send invitations for this site.'],
            $refused instanceof ApplicationInvitationConflict => [409, match ($refused->error) {
                ApplicationInvitationError::InvitationAccepted => 'This invitation was already accepted.',
                ApplicationInvitationError::ConcurrentInvitation => 'Another invitation to this address was being sent at the same moment. Try again.',
                default => 'This invitation is no longer pending.',
            }],
            $refused instanceof ApplicationInvitationNotFound => [404, 'The sign-in provider has no record of this invitation.'],
            $refused instanceof ApplicationInvitationMisconfigured => [503, 'Invitations are not available right now. Ask an administrator to check the sign-in provider settings.'],
            default => [503, 'The sign-in provider is unavailable. Try again shortly.'],
        };
        if ($status === 503) {
            // The reason and status only: never the address or a credential.
            Log::warning('Provider invitation request failed.', ['error' => $refused->error->value, 'status' => $refused->status]);
        }

        $response = $this->failure($status, $message);
        if ($refused->retryAfter !== null) {
            $response->header('Retry-After', (string) $refused->retryAfter);
        }

        return $response;
    }

    private function failure(int $status, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $isAdmin = $user->isAdmin();
        $invitations = ProviderInvitation::query()
            ->where('oauth_provider', SignIn::provider())
            ->when(! $isAdmin, fn ($query) => $query->where('inviter_user_id', $user->id))
            ->with('inviter:id,name,display_name')
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return [
            'is_admin' => $isAdmin,
            // Administrators invite without spending a balance.
            'balance' => $isAdmin ? null : $this->invites->availableBalance($user),
            'invitations' => $invitations->map(fn (ProviderInvitation $invitation): array => $this->present($invitation, $isAdmin))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(ProviderInvitation $invitation, bool $withInviter): array
    {
        $inviter = $withInviter ? $invitation->inviter : null;
        $status = $invitation->status === ProviderInvitation::PENDING && $invitation->expires_at->isPast()
            ? 'expired'
            : $invitation->status;

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'status' => $status,
            'email_sent' => $invitation->email_sent,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
            // Administrators see every invitation, so they also see who sent it.
            'invited_by' => $inviter === null ? null : ($inviter->display_name ?: $inviter->name),
        ];
    }
}
