<?php

namespace App\Console\Commands;

use App\Enums\MediaType;
use App\Enums\ModerationStatus;
use App\Models\Media;
use App\Services\Media\MediaModerationService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * When an administrator enables "Process videos before review", copy each
 * completed video awaiting review into reviewed storage so the transcoder can
 * start before approval. Approval then publishes that same copy. Videos that
 * failed to copy are retried on the next run.
 */
class PrepareVideosForReview extends Command
{
    protected $signature = 'media:prepare-videos-for-review {--limit=25 : Maximum videos to prepare in one run}';

    protected $description = 'Copy completed videos awaiting review into reviewed storage so they are transcoded before approval.';

    public function handle(SettingsService $settings, MediaModerationService $moderation): int
    {
        if (! $settings->preReviewVideoProcessing()) {
            $this->line('Processing videos before review is turned off.');

            return self::SUCCESS;
        }

        $ids = Media::query()
            ->where('type', MediaType::Video->value)
            ->where('upload_status', 'ready')
            ->where('moderation_status', ModerationStatus::Pending->value)
            ->whereNull('reviewed_object_key')
            // Least recently attempted first: a failed attempt touches the row,
            // so videos that keep failing cannot hold every batch.
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        $prepared = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                // Re-check under the row lock: the video may have been approved,
                // rejected, or deleted since it was listed.
                $ok = DB::transaction(function () use ($id, $moderation): bool {
                    $media = Media::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $media instanceof Media) {
                        return false;
                    }
                    if ($moderation->prepareForReview($media)) {
                        return true;
                    }
                    $media->touch();

                    return false;
                });
            } catch (\Throwable $exception) {
                report($exception);
                Media::query()->whereKey($id)->touch();
                $ok = false;
            }
            $ok ? $prepared++ : $failed++;
        }

        $this->info("Prepared {$prepared} video(s) for review; {$failed} not prepared.");

        return self::SUCCESS;
    }
}
