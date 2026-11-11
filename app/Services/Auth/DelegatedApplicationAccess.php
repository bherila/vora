<?php

namespace App\Services\Auth;

use App\Models\User;
use BWH\Auth\Models\AuthAuditLog;
use BWH\Auth\OAuth\DelegatedAccess\ApplicationAccessAdapter;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessSettings;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedCursor;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRefusal;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRequestContext;
use BWH\AuthManager\Embedded\EmbeddedContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Manages only the application administrator flag, never identity or domain data. */
final readonly class DelegatedApplicationAccess implements ApplicationAccessAdapter
{
    private const REMOVAL_NOTICE = 'Only the application administrator role is removed. The account, sign-in access, approval, moderation, content and feature permissions remain unchanged.';

    public function __construct(
        private DelegatedRequestContext $context,
        private DelegatedAccessSettings $settings,
        private DelegatedCursor $cursors,
    ) {}

    public function handle(string $actorSubject, array $payload): array
    {
        if (! SignIn::usesProvider() || $this->context->subject !== $actorSubject
            || (SignIn::mode() === SignIn::EMBEDDED && app(EmbeddedContext::class)->active())) {
            throw DelegatedRefusal::of(DelegatedRefusal::NOT_AUTHORIZED);
        }
        $actor = $this->bound($actorSubject);
        if ($actor === null || ! $this->eligibleAdministrator($actor)) {
            throw DelegatedRefusal::of(DelegatedRefusal::NOT_AUTHORIZED);
        }

        return match ($payload['operation']) {
            'capabilities' => ['controls' => ['application_admin' => true, 'workspace_roles' => [], 'provisioning' => false]],
            'subjects' => $this->subjects($actorSubject, $payload),
            'workspaces' => $this->emptyWorkspaces($actorSubject, $payload),
            'read', 'update', 'remove' => $this->account($actor, $payload),
            default => throw DelegatedRefusal::of(DelegatedRefusal::INVALID_REQUEST),
        };
    }

    private function bound(string $subject): ?User
    {
        // SQL collations may fold case. Compare both binding components byte for
        // byte before accepting a match; a same-email account is never a match.
        $matches = User::query()->where('oauth_provider', $this->settings->bindingIssuer())
            ->where('oauth_subject', $subject)->get()
            ->filter(fn (User $user): bool => $this->bindingMatches($user, $subject));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function bindingMatches(User $user, string $subject): bool
    {
        return $user->oauth_provider === $this->settings->bindingIssuer() && $user->oauth_subject === $subject && ! $user->trashed();
    }

    private function eligibleAdministrator(User $user): bool
    {
        return ! $user->trashed() && $user->isAdmin() && $user->canLogin() && $user->isApproved()
            && $user->hasVerifiedEmail() && ! $user->isBanned() && ! $user->isDeactivated();
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function subjects(string $actor, array $payload): array
    {
        $after = $this->cursors->after($actor, 'subjects', $payload);
        $search = $payload['query'] ?? null;
        $limit = $payload['limit'] ?? 50;
        $page = [];
        $last = $after;
        foreach (User::query()->where('oauth_provider', $this->settings->bindingIssuer())->whereNotNull('oauth_subject')
            ->where('id', '>', $after)->orderBy('id')->cursor() as $user) {
            $subject = $user->oauth_subject;
            if (! is_string($subject) || $subject === '' || ! $this->bindingMatches($user, $subject)
                || ($search !== null && mb_stripos($this->label($user), $search) === false && mb_stripos($user->email, $search) === false)) {
                continue;
            }
            if (count($page) === $limit) {
                return ['subjects' => $page, 'next_cursor' => $this->cursors->encode($actor, 'subjects', $last, $search)];
            }
            $page[] = ['subject' => $subject, 'label' => $this->label($user)];
            $last = $user->getKey();
        }

        return ['subjects' => $page, 'next_cursor' => null];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function emptyWorkspaces(string $actor, array $payload): array
    {
        $this->cursors->after($actor, 'workspaces', $payload);

        return ['workspaces' => [], 'next_cursor' => null];
    }

    private function label(User $user): string
    {
        return mb_strcut($user->display_name ?: $user->name ?: $user->oauth_subject, 0, 255, 'UTF-8');
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function account(User $actor, array $payload): array
    {
        $target = $this->bound($payload['subject']);

        return DB::transaction(function () use ($actor, $target, $payload): array {
            // Every delegated writer locks the same administrative directory in
            // ID order, plus actor/target. Other account updates must also wait
            // on these rows, so authority is checked again at the write boundary.
            $directory = User::withTrashed()->where(fn ($query) => $query->where('id', 1)->orWhere('is_admin', true)
                ->orWhereIn('id', array_filter([$actor->id, $target?->id])))
                ->orderBy('id')->lockForUpdate()->get();
            $actor = $directory->firstWhere('id', $actor->id);
            if ($actor === null || ! $this->bindingMatches($actor, $this->context->subject) || ! $this->eligibleAdministrator($actor)) {
                throw DelegatedRefusal::of(DelegatedRefusal::NOT_AUTHORIZED);
            }
            $target = $target === null ? null : $directory->firstWhere('id', $target->id);
            if ($target !== null && ! $this->bindingMatches($target, $payload['subject'])) {
                throw DelegatedRefusal::of(DelegatedRefusal::NOT_AUTHORIZED);
            }
            $state = $this->state($actor, $target, $payload['subject'], $directory);
            if ($payload['operation'] === 'read') {
                return $state;
            }
            if ($target === null) {
                throw DelegatedRefusal::of(DelegatedRefusal::NOT_PROVISIONED);
            }
            if ($payload['expected_revision'] !== $state['revision']) {
                throw DelegatedRefusal::of(DelegatedRefusal::REVISION_CONFLICT);
            }
            $admin = $payload['operation'] === 'remove' ? false : $payload['access']['application_admin'];
            if ($payload['operation'] === 'update' && $payload['access']['workspaces'] !== []) {
                throw DelegatedRefusal::of(DelegatedRefusal::ROLE_NOT_GRANTABLE);
            }
            if (($payload['operation'] === 'remove' && ! $state['allowed_edits']['remove'])
                || ($admin !== $target->isAdmin() && ! $state['allowed_edits']['application_admin'])) {
                throw DelegatedRefusal::of(DelegatedRefusal::NOT_AUTHORIZED);
            }
            if ($admin !== $target->isAdmin()) {
                $target->forceFill(['is_admin' => $admin])->save();
            }
            $result = $this->state($actor, $target, $payload['subject'], $directory);
            // Persist with the mutation even if the optional auth logger is off.
            // Failure rolls back the privilege change, never an unaudited success.
            AuthAuditLog::create([
                'user_id' => $target->id, 'acting_user_id' => $actor->id,
                'event' => 'delegated_application_access_'.$payload['operation'], 'auth_method' => 'delegated_assertion', 'succeeded' => true,
                'metadata' => ['provider' => $this->settings->bindingIssuer(), 'issuer' => $this->context->issuer,
                    'application' => $this->context->application, 'actor_subject' => $this->context->subject,
                    'subject' => $payload['subject'], 'jti' => $this->context->jti, 'operation_id' => $this->context->operationId,
                    'before_revision' => $state['revision'], 'after_revision' => $result['revision'],
                    'before' => $state['access'], 'after' => $result['access']],
            ]);

            return $result;
        });
    }

    /** @param Collection<int, User> $directory
     * @return array<string, mixed>
     */
    private function state(User $actor, ?User $target, string $subject, Collection $directory): array
    {
        if ($target === null) {
            return ['subject' => $subject, 'provisioned' => false, 'revision' => null, 'access' => null,
                'allowed_edits' => ['application_admin' => false, 'workspaces' => false, 'provision' => false, 'remove' => false]];
        }
        $eligible = $directory->filter(fn (User $user): bool => $this->eligibleAdministrator($user))->pluck('id')->all();
        $editable = $target->id !== 1 && $target->id !== $actor->id
            && ! ($this->eligibleAdministrator($target) && count($eligible) <= 1);

        return ['subject' => $subject, 'provisioned' => true,
            'revision' => hash('sha256', json_encode([$this->fingerprint($actor), $this->fingerprint($target), $eligible], JSON_THROW_ON_ERROR)),
            'access' => ['application_admin' => $target->isAdmin(), 'workspaces' => [], 'removal_notice' => self::REMOVAL_NOTICE],
            'allowed_edits' => ['application_admin' => $editable, 'workspaces' => false, 'provision' => false,
                'remove' => ! $target->isAdmin() || $editable],
            'provisioned_at' => $target->created_at?->toIso8601String(), 'last_seen_at' => $target->last_login_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function fingerprint(User $user): array
    {
        return $user->only(['id', 'oauth_provider', 'oauth_subject', 'name', 'display_name', 'email', 'is_admin', 'is_disabled',
            'approved_at', 'email_verified_at', 'banned_at', 'deactivated_at', 'deleted_at']);
    }
}
