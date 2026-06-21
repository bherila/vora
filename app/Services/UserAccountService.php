<?php

namespace App\Services;

use App\Models\AudienceMember;
use App\Models\Character;
use App\Models\FollowRequest;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
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
        foreach ($user->media()->withTrashed()->get() as $media) {
            if ($media instanceof Media) {
                $this->media->delete($media);
            }
        }

        DB::transaction(function () use ($user): void {
            $user->interestRatings()->delete();
            $user->pushSubscriptions()->delete();

            // audience_members has no FK to its polymorphic target, and
            // force-deleting the user cascades stories/media at the DB level
            // without firing the model hook that prunes them. Clear the
            // allowlists for this user's content explicitly (same reason the
            // story "involves" tags are cleaned by hand below).
            $this->purgeAudienceMembers($user);

            // Notifications about this user's actions live in other users' rows as
            // a denormalized JSON snapshot (actor_id/actor_name). Erasure must
            // remove that PII too, like the privacy audit trail's actor handling.
            DB::table('notifications')->where('data->actor_id', $user->id)->delete();

            // Bulk character deletion bypasses the Character `deleting` hook, so
            // explicitly remove the polymorphic story "involves" tags pointing at
            // this user and their characters (these have no FK to cascade).
            $characterIds = $user->characters()->withTrashed()->pluck('id');
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

    /**
     * Remove "specific people" allowlist grants attached to all of a user's
     * content before that content is cascade-deleted with the account.
     */
    private function purgeAudienceMembers(User $user): void
    {
        AudienceMember::query()
            ->where(function ($query) use ($user): void {
                $query->where('privacyable_type', (new Story)->getMorphClass())
                    ->whereIn('privacyable_id', $user->stories()->withTrashed()->select('id'));
            })
            ->orWhere(function ($query) use ($user): void {
                $query->where('privacyable_type', (new Media)->getMorphClass())
                    ->whereIn('privacyable_id', $user->media()->withTrashed()->select('id'));
            })
            ->orWhere(function ($query) use ($user): void {
                $query->where('privacyable_type', (new Post)->getMorphClass())
                    ->whereIn('privacyable_id', $user->posts()->withTrashed()->select('id'));
            })
            ->orWhere(function ($query) use ($user): void {
                $query->where('privacyable_type', (new Character)->getMorphClass())
                    ->whereIn('privacyable_id', $user->characters()->withTrashed()->select('id'));
            })
            // The user's own profile allowlist (they are the privacyable).
            ->orWhere(function ($query) use ($user): void {
                $query->where('privacyable_type', $user->getMorphClass())
                    ->where('privacyable_id', $user->id);
            })
            ->delete();
    }
}
