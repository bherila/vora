<?php

namespace App\Services\Library;

use App\Models\LibraryCollection;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LibraryBulkService
{
    public function __construct(private readonly LibraryService $library) {}

    /** @param list<string> $ulids */
    public function apply(User $user, string $action, array $ulids, ?string $targetUlid, ?string $sourceUlid): int
    {
        // Read immutable media IDs before the transaction. A consistent-read
        // snapshot taken before waiting for media locks could miss a concurrent
        // Save and incorrectly purge its retained bytes. Item ownership/existence
        // is checked again under locks before changing anything.
        $mediaIds = $action === 'delete'
            ? $user->libraryItems()->whereIn('ulid', $ulids)->whereNotNull('media_id')->pluck('media_id')->all()
            : [];

        return DB::transaction(function () use ($user, $action, $ulids, $targetUlid, $sourceUlid, $mediaIds): int {
            $target = $this->collection($user, $targetUlid);
            $source = $this->collection($user, $sourceUlid);

            // Match Save/final deletion's lock order before locking item rows.
            // All ownership and source membership checks precede any mutation.
            if ($action === 'delete') {
                Media::withTrashed()->whereIn('id', $mediaIds)->orderBy('id')->lockForUpdate()->get();
            }
            $items = $user->libraryItems()->whereIn('ulid', $ulids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === count($ulids), 404, 'Not found.');
            $ids = $items->modelKeys();
            if ($source !== null) {
                abort_unless($source->items()->whereIn('library_items.id', $ids)->count() === count($ids), 404, 'Not found.');
            }

            if ($target !== null) {
                foreach ($items as $item) {
                    $this->library->addToCollection($user, $item, $target->ulid);
                }
            }
            if ($source !== null) {
                $source->items()->detach($ids);
            }
            if ($action === 'delete') {
                foreach ($items as $item) {
                    $this->library->remove($user, $item);
                }
            }

            return $items->count();
        });
    }

    private function collection(User $user, ?string $ulid): ?LibraryCollection
    {
        return $ulid === null ? null : $user->libraryCollections()->where('ulid', $ulid)->lockForUpdate()->firstOrFail();
    }
}
