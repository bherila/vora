<?php

namespace App\Services\Library;

use App\Enums\RestrictionCapability;
use App\Models\Character;
use App\Models\LibraryCollection;
use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\User;
use App\Policies\MediaPolicy;
use App\Services\Moderation\RestrictionGate;
use App\Support\BlockGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class LibraryAccess
{
    public function canSaveMedia(User $viewer, Media $media): bool
    {
        if ($media->user_id === null || ! (new MediaPolicy)->view($viewer, $media)
            || ! $this->canViewMedia($viewer, $media)) {
            return false;
        }

        return $media->user_id === $viewer->id
            || ($media->audienceAllows($viewer)
                && ! BlockGraph::isDenied($viewer->id, $media->user_id)
                && ! BlockGraph::isHidden($viewer->id, $media->user_id, $media->character_id));
    }

    public function canViewCollection(User $viewer, LibraryCollection $collection): bool
    {
        return $collection->user_id === $viewer->id
            || ($this->canReceiveFrom($viewer, $collection->owner)
                && $collection->members->contains('id', $viewer->id));
    }

    public function canViewItem(User $viewer, LibraryItem $item): bool
    {
        if ($item->user_id === $viewer->id) {
            return true;
        }

        if (! $this->canReceiveFrom($viewer, $item->owner)) {
            return false;
        }

        if ($item->media !== null && ! $this->canViewMedia($viewer, $item->media)) {
            return false;
        }

        return $item->members->contains('id', $viewer->id)
            || $item->collections->contains(fn (LibraryCollection $collection): bool => $collection->members->contains('id', $viewer->id));
    }

    public function canViewMedia(User $viewer, Media $media): bool
    {
        if (! $media->isReady()) {
            return false;
        }

        if ($media->user_id === $viewer->id) {
            return true;
        }

        return $media->isApprovedContent()
            && ! app(RestrictionGate::class)->denies($viewer, RestrictionCapability::MediaView);
    }

    /** @return Builder<LibraryItem> */
    public function sharedItems(User $viewer): Builder
    {
        $query = LibraryItem::query()
            ->where('library_items.user_id', '!=', $viewer->id)
            ->where(function (Builder $shared) use ($viewer): void {
                $shared->whereHas('members', fn (Builder $members) => $members->where('users.id', $viewer->id))
                    ->orWhereHas('collections.members', fn (Builder $members) => $members->where('users.id', $viewer->id));
            });
        $this->constrainSharedOwner($query, $viewer, 'library_items.user_id');

        return $query;
    }

    /** @return Builder<LibraryCollection> */
    public function collections(User $viewer): Builder
    {
        return LibraryCollection::query()->where(function (Builder $query) use ($viewer): void {
            $query->where('user_id', $viewer->id)->orWhere(function (Builder $shared) use ($viewer): void {
                $shared->whereHas('members', fn (Builder $members) => $members->where('users.id', $viewer->id));
                $this->constrainSharedOwner($shared, $viewer, 'library_collections.user_id');
            });
        });
    }

    /** @return Builder<Character> */
    public function personas(User $viewer): Builder
    {
        return Character::query()->viewableBy($viewer)
            ->whereHas('user', fn (Builder $owner) => $owner->active()->whereNotNull('approved_at'));
    }

    private function constrainSharedOwner(Builder $query, User $viewer, string $ownerColumn): void
    {
        // Library grants respect blocks even for admins; review access is separate.
        $query->whereHas('owner', fn (Builder $owner) => $owner->active()->whereNotNull('approved_at'))
            ->whereNotExists(fn (QueryBuilder $blocks) => BlockGraph::constrainDenied($blocks, $ownerColumn, $viewer->id))
            ->whereNotExists(fn (QueryBuilder $blocks) => BlockGraph::constrainHidden($blocks, $ownerColumn, $viewer->id));
    }

    private function canReceiveFrom(User $viewer, ?User $owner): bool
    {
        return $owner instanceof User && $owner->isActive() && $owner->isApproved()
            && ! BlockGraph::isDenied($viewer->id, $owner->id)
            && ! BlockGraph::isHidden($viewer->id, $owner->id);
    }
}
