<?php

namespace App\Services\Auth;

use App\Models\User;
use BWH\Auth\Models\AuthAuditLog;
use BWH\Auth\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/** Vora administrator authority, shared by local and delegated management. */
final class ApplicationAdministrators
{
    public function eligible(User $user): bool
    {
        return ! $user->trashed() && $user->isAdmin() && $user->canLogin() && $user->isApproved()
            && $user->hasVerifiedEmail() && ! $user->isBanned() && ! $user->isDeactivated();
    }

    /**
     * Call within the mutation transaction. All role writers lock in ID order.
     *
     * @return Collection<int, User>
     */
    public function lockDirectory(int $actorId, ?int $targetId): Collection
    {
        return User::withTrashed()->where(fn ($query) => $query->where('id', 1)->orWhere('is_admin', true)
            ->orWhereIn('id', array_filter([$actorId, $targetId])))
            ->orderBy('id')->lockForUpdate()->get();
    }

    /** @param Collection<int, User> $directory */
    public function canChange(User $actor, User $target, Collection $directory): bool
    {
        return $this->eligible($actor) && $target->id !== 1 && $target->id !== $actor->id
            && ! ($this->eligible($target) && $directory->filter(fn (User $user): bool => $this->eligible($user))->count() <= 1);
    }

    /** Persist in the same transaction as a local role mutation, even if auth logging is disabled. */
    public function auditLocalChange(Request $request, User $actor, User $target, bool $previous): void
    {
        $audit = AuthAuditLog::create([
            'user_id' => $target->id,
            'acting_user_id' => $actor->id,
            'email' => $target->email,
            'event' => 'application_admin_changed',
            'auth_method' => 'admin',
            'succeeded' => true,
            'ip_address' => ClientIp::resolve($request),
            'user_agent' => $request->userAgent(),
            'metadata' => ['before' => ['application_admin' => $previous], 'after' => ['application_admin' => $target->isAdmin()]],
        ]);
        if (! $audit->exists) {
            throw new RuntimeException('Application administrator audit was not persisted.');
        }
    }
}
