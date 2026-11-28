<?php

namespace App\Console\Commands;

use App\Services\Media\VideoMomentService;
use Illuminate\Console\Command;

/**
 * Collects the transcoder frame worker's answers for pending moments: marks
 * them ready (deleting the author's provisional capture) or failed, and removes
 * answered requests from the source disk.
 */
class SyncMomentFrames extends Command
{
    protected $signature = 'media:sync-moment-frames {--limit=200 : Most pending moments to check per run}';

    protected $description = 'Check the frame worker for pending moments and publish ready frames.';

    public function handle(VideoMomentService $moments): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $changed = $moments->syncPendingFrames($limit);
        $this->info("Updated {$changed} moment(s).");

        return self::SUCCESS;
    }
}
