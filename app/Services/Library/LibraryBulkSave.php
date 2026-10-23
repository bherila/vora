<?php

namespace App\Services\Library;

use App\Models\LibraryItem;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\AccountStorageQuota;
use App\Services\Media\BulkTransferSelection;
use Illuminate\Support\Facades\DB;

class LibraryBulkSave
{
    public function __construct(private readonly LibraryService $library, private readonly AccountStorageQuota $quota, private readonly BulkTransferSelection $selection) {}

    /** @param list<string> $ulids */
    public function save(User $user, string $source, array $ulids): int
    {
        $mediaIds = $source === 'events' ? Media::whereIn('ulid', $ulids)->pluck('id')->all()
            : LibraryItem::whereIn('ulid', $ulids)->whereNotNull('media_id')->pluck('media_id')->all();

        return DB::transaction(function () use ($user, $source, $ulids, $mediaIds): int {
            $user = $this->quota->lock($user);
            abort_unless($user->canUseLibrary(), 403, 'Storage is not enabled for this account.');
            // Match single Save and deletion: account -> ordered media -> items.
            Media::withTrashed()->whereIn('id', $mediaIds)->orderBy('id')->lockForUpdate()->get();
            if ($source === 'library') {
                LibraryItem::whereIn('ulid', $ulids)->orderBy('id')->lockForUpdate()->get();
            }
            $items = $this->selection->resolve($user, $source, $ulids);
            foreach ($items as $item) {
                if ($item instanceof Media) {
                    $this->library->saveMedia($user, $item->ulid, null);
                } else {
                    $this->library->saveItem($user, $item->ulid, null);
                }
            }

            return $items->count();
        });
    }
}
