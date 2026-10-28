<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Chat\ChatState;
use App\Services\Profile\RecentProfileTrail;
use BWH\Auth\OAuth\Lifecycle\IdentityTombstone;
use BWH\Auth\OAuth\Lifecycle\IdentityTombstoneHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Applies a deletion at the identity provider here, as if the person had
 * deleted their own account: the account is soft-deleted, so administrators
 * can still restore or purge it from Admin → Users. Returning tells the
 * provider the deletion is done; throwing leaves its tombstone to come back
 * on a later run (`bherila-auth:consume-identity-tombstones`).
 */
class ProviderIdentityDeletion implements IdentityTombstoneHandler
{
    public function __construct(
        private readonly ChatState $chatState,
        private readonly RecentProfileTrail $recentProfiles,
    ) {}

    public function handle(IdentityTombstone $tombstone): void
    {
        /** @var User|null $user */
        $user = User::withTrashed()
            ->where('oauth_provider', $tombstone->provider)
            ->where('oauth_subject', $tombstone->subject)
            ->first();

        // Never here, or already deleted: done.
        if ($user === null || $user->trashed()) {
            return;
        }

        // The same holds as a person deleting their own account. Each refusal
        // waits for an administrator, and the tombstone is retried until then.
        $reason = match (true) {
            $user->isOnLegalHold() => 'legal_hold',
            $user->id === 1 => 'primary_admin',
            default => null,
        };
        if ($reason !== null) {
            Log::warning('Provider deletion not applied.', ['user_id' => $user->id, 'reason' => $reason]);
            throw new RuntimeException('Provider deletion not applied: '.$reason);
        }

        // Committed before returning: the provider is told straight after.
        DB::transaction(function () use ($user): void {
            $this->recentProfiles->clear($user);
            $this->chatState->touchUserAndPeers($user);
            $user->delete();
        });
    }
}
