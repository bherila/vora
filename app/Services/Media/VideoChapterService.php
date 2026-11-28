<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Models\User;
use App\Models\VideoChapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads and replaces a video's chapters, renders them as a WebVTT chapters
 * track, and anonymizes them when the publication is released.
 */
class VideoChapterService
{
    /**
     * Replace every chapter of the video, in one transaction.
     *
     * @param  list<array{start_ms: int, title: string}>  $chapters
     * @return Collection<int, VideoChapter>
     */
    public function replace(Media $media, User $editor, array $chapters): Collection
    {
        usort($chapters, fn (array $a, array $b): int => $a['start_ms'] <=> $b['start_ms']);

        DB::transaction(function () use ($media, $editor, $chapters): void {
            $media->chapters()->delete();
            $now = now();
            $rows = array_map(fn (array $chapter): array => [
                'media_id' => $media->id,
                'start_ms' => $chapter['start_ms'],
                'title' => $chapter['title'],
                'user_id' => $editor->id,
                'character_id' => $media->character_id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chapters);
            if ($rows !== []) {
                VideoChapter::query()->insert($rows);
            }
        });

        return $media->chapters()->get();
    }

    /**
     * The payload shape: time and title only, never the author.
     *
     * @param  iterable<VideoChapter>  $chapters
     * @return list<array{start_ms: int, title: string}>
     */
    public function present(iterable $chapters): array
    {
        $out = [];
        foreach ($chapters as $chapter) {
            $out[] = ['start_ms' => $chapter->start_ms, 'title' => $chapter->title];
        }

        return $out;
    }

    /**
     * Keep chapters on a video retained only for Library saves, without the
     * uploader or persona who wrote them.
     */
    public function anonymizeForMedia(Media $media): void
    {
        VideoChapter::query()->where('media_id', $media->id)->update(['user_id' => null, 'character_id' => null]);
    }

    /**
     * Render a WebVTT chapters track. Each cue ends where the next chapter
     * starts; the last ends at the video's duration when known.
     *
     * @param  iterable<VideoChapter>  $chapters
     */
    public function toWebVtt(iterable $chapters, ?int $durationMs): string
    {
        $list = [];
        foreach ($chapters as $chapter) {
            $list[] = $chapter;
        }

        $lines = ['WEBVTT', ''];
        foreach ($list as $index => $chapter) {
            $next = $list[$index + 1] ?? null;
            $end = $next instanceof VideoChapter
                ? $next->start_ms
                : max($chapter->start_ms + 1000, $durationMs ?? $chapter->start_ms + 24 * 3_600_000);
            $lines[] = (string) ($index + 1);
            $lines[] = $this->timestamp($chapter->start_ms).' --> '.$this->timestamp($end);
            $lines[] = htmlspecialchars($chapter->title, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function timestamp(int $ms): string
    {
        $hours = intdiv($ms, 3_600_000);
        $minutes = intdiv($ms % 3_600_000, 60_000);
        $seconds = intdiv($ms % 60_000, 1000);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $seconds, $ms % 1000);
    }
}
