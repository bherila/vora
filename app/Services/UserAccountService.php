<?php

namespace App\Services;

use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\StoryInvolvement;
use App\Models\User;
use App\Services\Media\MediaService;
use Illuminate\Support\Facades\DB;

/**
 * Account lifecycle operations that span the database and object storage.
 */
class UserAccountService
{
    public function __construct(private readonly MediaService $media) {}

    /**
     * Permanently delete a user and everything they own: all media (gallery +
     * profile pictures, including their characters' avatars) and the backing
     * storage objects, their characters, interest ratings, and follow requests.
     *
     * Storage objects are removed first because force-deleting the user only
     * cascades database rows, which would otherwise orphan the R2 objects.
     */
    public function purge(User $user): void
    {
        foreach ($user->media()->get() as $media) {
            if ($media instanceof Media) {
                $this->media->delete($media);
            }
        }

        DB::transaction(function () use ($user): void {
            $user->interestRatings()->delete();

            // Bulk character deletion bypasses the Character `deleting` hook, so
            // explicitly remove the polymorphic story "involves" tags pointing at
            // this user and their characters (these have no FK to cascade).
            $characterIds = $user->characters()->pluck('id');
            StoryInvolvement::query()
                ->where('involvable_type', 'character')
                ->whereIn('involvable_id', $characterIds)
                ->delete();
            StoryInvolvement::query()
                ->where('involvable_type', 'user')
                ->where('involvable_id', $user->id)
                ->delete();

            $user->characters()->delete();
            FollowRequest::query()
                ->where('requester_id', $user->id)
                ->orWhere('recipient_id', $user->id)
                ->delete();

            $user->forceDelete();
        });
    }
}
