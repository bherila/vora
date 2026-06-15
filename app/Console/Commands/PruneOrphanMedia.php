<?php

namespace App\Console\Commands;

use App\Enums\MediaPurpose;
use App\Models\Character;
use App\Models\Media;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\Media\MediaService;
use Illuminate\Console\Command;

/**
 * Garbage-collects abandoned uploads: media rows left in `upload_status =
 * pending` past a grace window (the client created the record but never
 * completed the upload). Any object that did land in storage for such a row is
 * deleted too, then the row is removed.
 *
 * Also collects orphaned profile-picture media — ready avatars that no user or
 * character references any more (e.g. replaced or removed before synchronous
 * cleanup existed). Soft-deleted (not purged) owners still count as references,
 * so their avatars survive until the account is purged or restored.
 *
 * Truly orphaned objects with no row at all (e.g. a row deleted before its PUT
 * finished) are best handled by a short R2 lifecycle rule on the upload prefix;
 * see docs/media/storage-and-buckets.md.
 */
class PruneOrphanMedia extends Command
{
    protected $signature = 'media:prune-orphans {--hours=24 : Age in hours after which a still-pending upload is pruned} {--dry-run : Report what would be pruned without deleting}';

    protected $description = 'Prune media rows whose upload was never completed, and orphaned profile pictures (and any stray objects they left behind).';

    public function handle(FileStorageService $storage, MediaService $mediaService): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($hours);
        $thumbnailDisk = (string) config('media.thumbnail_disk');

        $stale = Media::query()
            ->where('upload_status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        $objectsDeleted = 0;
        foreach ($stale as $media) {
            if ($dryRun) {
                $this->line("would prune pending media #{$media->id} ({$media->disk}/{$media->object_key})");

                if ($media->thumbnail_key !== null) {
                    $this->line("  and thumbnail ({$thumbnailDisk}/{$media->thumbnail_key})");
                }

                continue;
            }

            if ($storage->fileExists($media->disk, $media->object_key)) {
                $storage->deleteFile($media->disk, $media->object_key);
                $objectsDeleted++;
            }

            // A stale pending row may also have a client-uploaded thumbnail
            // object (its own presigned PUT); remove it so abandoned uploads
            // don't leak thumbnail objects into storage indefinitely.
            if ($media->thumbnail_key !== null
                && $storage->fileExists($thumbnailDisk, $media->thumbnail_key)) {
                $storage->deleteFile($thumbnailDisk, $media->thumbnail_key);
                $objectsDeleted++;
            }

            $media->delete();
        }

        // Orphaned ready profile pictures: referenced by no (live or soft-deleted)
        // user or character. The pending pass above already covers incomplete ones.
        $orphanedAvatars = Media::query()
            ->where('purpose', MediaPurpose::ProfilePicture->value)
            ->where('upload_status', 'ready')
            ->where('created_at', '<', $cutoff)
            ->whereNotIn('id', User::withTrashed()->whereNotNull('profile_picture_media_id')->select('profile_picture_media_id'))
            ->whereNotIn('id', Character::query()->whereNotNull('profile_picture_media_id')->select('profile_picture_media_id'))
            ->get();

        foreach ($orphanedAvatars as $media) {
            if ($dryRun) {
                $this->line("would prune orphaned profile picture #{$media->id} ({$media->disk}/{$media->object_key})");

                continue;
            }

            $mediaService->delete($media);
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->info("{$verb} {$stale->count()} abandoned upload(s) and {$orphanedAvatars->count()} orphaned profile picture(s); removed {$objectsDeleted} stray object(s).");

        return self::SUCCESS;
    }
}
