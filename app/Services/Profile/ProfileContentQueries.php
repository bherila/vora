<?php

namespace App\Services\Profile;

use App\Enums\MediaPurpose;
use App\Enums\ModerationStatus;
use App\Enums\RestrictionCapability;
use App\Enums\StoryStatus;
use App\Models\Character;
use App\Models\Media;
use App\Models\Post;
use App\Models\Story;
use App\Models\StoryAuthor;
use App\Models\User;
use App\Services\Moderation\RestrictionGate;
use App\Services\Privacy\ProfileGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single source of the per-identity, per-viewer content queries behind a
 * profile's tabs. Listings, tab-count badges, the combined "Latest" strip, and
 * the identity rail's totals all build on these exact builders, so a count can
 * never imply content its listing would not actually return.
 *
 * Every builder applies the item's own audience via the shared `viewableBy`
 * scope; callers must separately ensure the viewer may see the profile at all
 * ({@see ProfileGate}).
 */
class ProfileContentQueries
{
    public function __construct(private readonly RestrictionGate $restrictions) {}

    /**
     * @return Builder<Media>
     */
    public function media(User $user, User $viewer, ?Character $character): Builder
    {
        $query = Media::query()
            ->where('user_id', $user->id)
            ->where('purpose', MediaPurpose::Gallery->value)
            ->where('upload_status', 'ready')
            ->viewableBy($viewer);

        if ($this->restrictions->denies($viewer, RestrictionCapability::MediaView)) {
            $query->where('media.user_id', $viewer->id);
        }

        $this->scopeToIdentity($query, $character);
        $this->hideUnapprovedFromOthers($query, $viewer, $user);

        return $query;
    }

    /**
     * @return Builder<Post>
     */
    public function posts(User $user, User $viewer, ?Character $character): Builder
    {
        $query = Post::query()
            ->where('user_id', $user->id)
            ->viewableBy($viewer);

        $this->scopeToIdentity($query, $character);
        $this->hideUnapprovedFromOthers($query, $viewer, $user);

        return $query;
    }

    /**
     * @return Builder<Story>
     */
    public function stories(User $user, User $viewer, ?Character $character): Builder
    {
        // Stories are scoped by authorship identity: an accepted story_authors
        // row for this user, written as the selected character (or as the main
        // identity when none is selected).
        $query = Story::query()
            ->viewableBy($viewer)
            ->whereHas('authors', function (Builder $authors) use ($user, $character): void {
                $authors
                    ->where('user_id', $user->id)
                    ->where('status', StoryAuthor::STATUS_ACCEPTED)
                    ->when(
                        $character instanceof Character,
                        fn (Builder $query): Builder => $query->where('character_id', $character?->id),
                        fn (Builder $query): Builder => $query->whereNull('character_id'),
                    );
            });

        // Non-owners only ever see another profile's published, approved stories.
        if ($viewer->id !== $user->id && ! $viewer->isAdmin()) {
            $query->where('status', StoryStatus::Published->value)
                ->where('moderation_status', ModerationStatus::Approved->value);
        }

        return $query;
    }

    /**
     * Total items (media + stories + posts) per identity, for the identity
     * rail's per-avatar counts. Owner-only by design: it runs three count
     * queries per identity, which is fine for one owner loading their own /me
     * but would multiply per-visitor work on every profile view.
     *
     * @param  Collection<int, Character>  $characters
     * @return array{self: int, characters: array<int, int>}
     */
    public function identityTotals(User $owner, Collection $characters): array
    {
        $total = fn (?Character $character): int => $this->media($owner, $owner, $character)->count()
            + $this->stories($owner, $owner, $character)->count()
            + $this->posts($owner, $owner, $character)->count();

        return [
            'self' => $total(null),
            'characters' => $characters
                ->mapWithKeys(fn (Character $character): array => [$character->id => $total($character)])
                ->all(),
        ];
    }

    /**
     * Media/posts carry a character_id: scope to that character, or to the main
     * user's own (character-less) content.
     *
     * @param  Builder<Media>|Builder<Post>  $query
     */
    private function scopeToIdentity(Builder $query, ?Character $character): void
    {
        if ($character instanceof Character) {
            $query->where('character_id', $character->id);
        } else {
            $query->whereNull('character_id');
        }
    }

    /**
     * @param  Builder<Media>|Builder<Post>  $query
     */
    private function hideUnapprovedFromOthers(Builder $query, User $viewer, User $owner): void
    {
        if ($viewer->id !== $owner->id && ! $viewer->isAdmin()) {
            $query->where('moderation_status', ModerationStatus::Approved->value);
        }
    }
}
