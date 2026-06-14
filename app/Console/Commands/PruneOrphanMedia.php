<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\FileStorageService;
use Illuminate\Console\Command;

/**
 * Garbage-collects abandoned uploads: media rows left in `upload_status =
 * pending` past a grace window (the client created the record but never
 * completed the upload). Any object that did land in storage for such a row is
 * deleted too, then the row is removed.
 *
 * Truly orphaned objects with no row at all (e.g. a row deleted before its PUT
 * finished) are best handled by a short R2 lifecycle rule on the upload prefix;
 * see docs/media/storage-and-buckets.md.
 */
class PruneOrphanMedia extends Command
{
    protected $signature = 'media:prune-orphans {--hours=24 : Age in hours after which a still-pending upload is pruned} {--dry-run : Report what would be pruned without deleting}';

    protected $description = 'Prune media rows whose upload was never completed (and any stray object they left behind).';

    public function handle(FileStorageService $storage): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($hours);

        $stale = Media::query()
            ->where('upload_status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No orphaned uploads to prune.');

            return self::SUCCESS;
        }

        $objectsDeleted = 0;
        foreach ($stale as $media) {
            if ($dryRun) {
                $this->line("would prune media #{$media->id} ({$media->disk}/{$media->object_key})");

                continue;
            }

            if ($storage->fileExists($media->disk, $media->object_key)) {
                $storage->deleteFile($media->disk, $media->object_key);
                $objectsDeleted++;
            }

            $media->delete();
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->info("{$verb} {$stale->count()} orphaned upload(s); removed {$objectsDeleted} stray object(s).");

        return self::SUCCESS;
    }
}
