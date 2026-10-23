<?php

namespace App\Services\Media;

use App\Enums\MediaPurpose;
use App\Enums\RestrictionCapability;
use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\User;
use App\Services\Auth\AgeEligibility;
use App\Services\Library\LibraryAccess;
use App\Support\InstanceFeatures;
use Illuminate\Support\Collection;

class BulkTransferSelection
{
    public function __construct(private readonly LibraryAccess $access) {}

    /** @param list<string> $ulids @return Collection<int, Media|LibraryItem> */
    public function resolve(User $user, string $source, array $ulids): Collection
    {
        abort_unless(! $user->trashed() && $user->isActive() && ! $user->isBanned() && $user->isApproved()
            && $user->hasVerifiedEmail() && app(AgeEligibility::class)->reason($user) === null, 404, 'Not found.');
        abort_unless(InstanceFeatures::enabled($source === 'events' ? 'events' : 'library'), 404, 'Not found.');
        // A ZIP can outlive a normal request. Do not reuse RestrictionGate's
        // request-scoped cache after a new restriction is imposed mid-stream.
        $restricted = $user->restrictions()->active()->where('capability', RestrictionCapability::MediaView->value)->exists();
        if ($source === 'events') {
            $items = Media::query()->where('purpose', MediaPurpose::Event->value)->whereIn('ulid', $ulids)
                ->with(['event.members', 'user', 'character.user'])->orderBy('id')->get();
            abort_unless($items->count() === count($ulids), 404, 'Not found.');
            foreach ($items as $item) {
                // Ordinary publication access, never an administrative review bypass.
                abort_unless((! $restricted || $item->user_id === $user->id) && $this->access->canSaveMedia($user, $item), 404, 'Not found.');
            }
        } else {
            abort_unless($source === 'library', 404, 'Not found.');
            $items = LibraryItem::query()->whereIn('ulid', $ulids)
                ->with(['owner', 'media', 'members', 'collections.members'])->orderBy('id')->get();
            abort_unless($items->count() === count($ulids), 404, 'Not found.');
            foreach ($items as $item) {
                abort_unless($this->access->canViewItem($user, $item)
                    && ($item->media === null || ((! $restricted || $item->media->user_id === $user->id)
                        && $this->access->canViewMedia($user, $item->media))), 404, 'Not found.');
            }
        }

        return $items;
    }
}
