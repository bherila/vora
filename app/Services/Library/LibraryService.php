<?php

namespace App\Services\Library;

use App\Enums\MediaPurpose;
use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\AccountStorageQuota;
use App\Services\Media\MediaService;
use Illuminate\Support\Facades\DB;

class LibraryService
{
    public function __construct(
        private readonly LibraryAccess $access,
        private readonly MediaService $mediaService,
        private readonly AccountStorageQuota $quota,
    ) {}

    public function saveMedia(User $user, string $ulid, ?string $collectionUlid): LibraryItem
    {
        return DB::transaction(function () use ($user, $ulid, $collectionUlid): LibraryItem {
            $user = $this->quota->lock($user);
            $media = Media::query()->where('ulid', $ulid)->lockForUpdate()->first();
            // Administrative review access is not ordinary viewing permission.
            abort_unless($media instanceof Media && $this->access->canSaveMedia($user, $media), 404, 'Not found.');

            $item = $this->referenceMedia($user, $media);
            $this->addToCollection($user, $item, $collectionUlid);

            return $item;
        });
    }

    public function saveItem(User $user, string $ulid, ?string $collectionUlid): LibraryItem
    {
        $source = LibraryItem::query()->where('ulid', $ulid)->firstOrFail();

        return DB::transaction(function () use ($user, $source, $collectionUlid): LibraryItem {
            $user = $this->quota->lock($user);
            // Establish the transaction's read snapshot after the source lock,
            // so a concurrent Save's existing reference is visible on MariaDB.
            $media = $source->media_id === null ? null
                : Media::withTrashed()->whereKey($source->media_id)->lockForUpdate()->firstOrFail();
            $source = LibraryItem::query()->whereKey($source->id)->lockForUpdate()
                ->with(['owner', 'media', 'members', 'collections.members'])->firstOrFail();
            abort_unless($this->access->canViewItem($user, $source)
                && ($media === null || $this->access->canViewMedia($user, $media)), 404, 'Not found.');

            if ($source->user_id === $user->id) {
                $item = $source;
            } elseif ($media instanceof Media) {
                $item = $this->referenceMedia($user, $media, $source->title);
            } else {
                // A saved note is a private text snapshot, independent of edits
                // to or deletion of the original note and its owner.
                $item = $user->libraryItems()->firstOrCreate(['source_item_ulid' => $source->ulid], [
                    'source' => 'saved', 'title' => $source->title, 'body' => $source->body,
                ]);
            }
            $this->addToCollection($user, $item, $collectionUlid);

            return $item;
        });
    }

    public function referenceMedia(User $user, Media $media, ?string $title = null): LibraryItem
    {
        $user = $this->quota->lock($user);
        // Callers lock the account before the source media. Pending private
        // uploads already reserve their declared size in MediaUploadService.
        $existing = $user->libraryItems()->where('media_id', $media->id)->first();
        if ($existing instanceof LibraryItem) {
            return $existing;
        }
        $this->quota->assertCanReference($user, $media);

        return $user->libraryItems()->create([
            'media_id' => $media->id,
            'source' => $media->user_id === $user->id ? 'upload' : 'saved',
            'title' => $title ?? $media->title,
        ]);
    }

    public function addToCollection(User $user, LibraryItem $item, ?string $ulid): void
    {
        if ($ulid === null) {
            return;
        }
        $collection = $user->libraryCollections()->where('ulid', $ulid)->firstOrFail();
        abort_unless($item->user_id === $user->id, 404, 'Not found.');
        $collection->items()->syncWithoutDetaching([$item->id]);
    }

    public function remove(User $user, LibraryItem $item): void
    {
        DB::transaction(function () use ($user, $item): void {
            // Save and final deletion serialize on the same media row. This
            // prevents a concurrent Save retaining an object being removed.
            $media = $item->media_id === null ? null
                : Media::withTrashed()->whereKey($item->media_id)->lockForUpdate()->first();
            $item = $user->libraryItems()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $source = $item->source;
            $item->delete();

            if (! $media instanceof Media) {
                return;
            }
            if ($media->purpose === MediaPurpose::Library && $media->user_id === $user->id && $source === 'upload') {
                $this->mediaService->softDelete($media);
            }
            if ($media->user_id === null && ! $media->libraryItems()->exists()) {
                $this->mediaService->delete($media);
            }
        });
    }
}
