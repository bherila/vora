<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Account mutations lock the account first, then media, before checking usage. */
class AccountStorageQuota
{
    public function __construct(
        private readonly AccountStorageUsage $usage,
        private readonly AccountStorageLimit $limits,
    ) {}

    public function lock(User $user): User
    {
        return $this->lockId($user->id);
    }

    public function lockId(int $userId): User
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Storage quota checks require an account transaction.');
        }

        return User::withTrashed()->whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    public function allows(User $lockedUser, ?int $additionalBytes, int $releasedReservation = 0): bool
    {
        $limit = $this->limits->forUser($lockedUser);
        if ($limit === null) {
            return true;
        }
        if ($additionalBytes === null || $additionalBytes < 0) {
            return false;
        }
        $usage = $this->usage->forUser($lockedUser);

        return $usage['active_unknown_size_file_count'] === 0
            && $limit >= $usage['used_bytes'] - $releasedReservation + $additionalBytes;
    }

    public function assertCanGrow(User $lockedUser, ?int $additionalBytes): void
    {
        if (! $this->allows($lockedUser, $additionalBytes)) {
            throw ValidationException::withMessages([
                'storage' => 'Your account storage limit has been reached or a file size could not be verified. Remove uploads or saved files, or ask an administrator to increase your limit.',
            ]);
        }
    }

    public function assertCanReference(User $lockedUser, Media $media): void
    {
        // Owning an active upload already accounts for these bytes. A restored
        // reference to a retained-deleted upload consumes storage again.
        if ($media->user_id === $lockedUser->id && ! $media->trashed()) {
            return;
        }
        $this->assertCanGrow($lockedUser, $media->size_bytes);
    }
}
