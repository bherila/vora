import { chapterIndexAt } from '@/media/chapters';
import type { VideoChapter } from '@/media/types';
import { usePlaybackState, type VideoPlayback } from '@/media/videoPlayback';
import { formatClock } from '@/media/videoTime';

interface ChapterListProps {
  chapters: readonly VideoChapter[];
  playback: VideoPlayback;
}

/** The chapters as a list of jump buttons, with the playing one marked. */
export function ChapterList({ chapters, playback }: ChapterListProps) {
  const { time } = usePlaybackState(playback);
  const current = chapterIndexAt(chapters, time);

  return (
    <nav aria-label="Chapters">
      <ol className="space-y-1">
        {chapters.map((chapter, index) => (
          <li key={chapter.start_ms}>
            <button
              type="button"
              onClick={() => playback.seek(chapter.start_ms / 1000)}
              aria-current={index === current ? 'true' : undefined}
              className={`flex w-full items-baseline gap-3 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted ${index === current ? 'bg-muted font-medium' : ''}`}
            >
              <span className="font-mono text-xs tabular-nums text-muted-foreground">{formatClock(chapter.start_ms / 1000)}</span>
              <span className="min-w-0 break-words">{chapter.title}</span>
            </button>
          </li>
        ))}
      </ol>
    </nav>
  );
}
