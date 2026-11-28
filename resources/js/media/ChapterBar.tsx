import { chapterEndMs, chapterIndexAt } from '@/media/chapters';
import type { VideoChapter } from '@/media/types';
import { usePlaybackState, type VideoPlayback } from '@/media/videoPlayback';
import { formatClock } from '@/media/videoTime';

interface ChapterBarProps {
  chapters: readonly VideoChapter[];
  playback: VideoPlayback;
  /** Duration from the server, used until the player reports its own. */
  durationMs: number | null;
}

const WIDTH = 1000;
const HEIGHT = 10;
const GAP = 2;

/**
 * A segmented strip under the video, one segment per chapter, with the current
 * one highlighted and a playhead. Drawn as SVG so segment widths need no
 * inline styles. Mouse users click a segment to jump to it; the chapter list
 * offers the same navigation to keyboard and screen-reader users.
 */
export function ChapterBar({ chapters, playback, durationMs: serverDurationMs }: ChapterBarProps) {
  const { time, duration } = usePlaybackState(playback);
  const durationMs = Number.isFinite(duration) && duration > 0 ? duration * 1000 : serverDurationMs;
  if (chapters.length === 0 || durationMs === null || durationMs <= 0) {
    return null;
  }
  const current = chapterIndexAt(chapters, time);
  const scale = WIDTH / durationMs;
  const playhead = Math.min(WIDTH, Math.max(0, time * 1000 * scale));

  return (
    <svg
      viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
      preserveAspectRatio="none"
      className="h-2.5 w-full cursor-pointer"
      aria-hidden="true"
      data-testid="chapter-bar"
    >
      {chapters.map((chapter, index) => {
        const end = chapterEndMs(chapters, index, durationMs) ?? durationMs;
        const x = chapter.start_ms * scale;
        const width = Math.max(1, (end - chapter.start_ms) * scale - (index < chapters.length - 1 ? GAP : 0));
        return (
          <rect
            key={chapter.start_ms}
            x={x}
            y={0}
            width={width}
            height={HEIGHT}
            rx={2}
            className={index === current ? 'fill-primary' : 'fill-muted-foreground/30 hover:fill-muted-foreground/60'}
            onClick={() => playback.seek(chapter.start_ms / 1000)}
          >
            <title>{`${formatClock(chapter.start_ms / 1000)} ${chapter.title}`}</title>
          </rect>
        );
      })}
      <rect x={Math.max(0, playhead - 1)} y={0} width={2} height={HEIGHT} className="pointer-events-none fill-foreground" />
    </svg>
  );
}
