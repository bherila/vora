import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Link as LinkIcon, RotateCcw, SkipBack, SkipForward } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { chapterIndexAt, nextChapterStart, previousChapterStart } from '@/media/chapters';
import type { VideoChapter } from '@/media/types';
import { formatFrameRate, usePlaybackState,type VideoPlayback } from '@/media/videoPlayback';
import { MIN_RESUME_SECONDS, readResumePosition, writeResumePosition } from '@/media/videoResume';
import { formatClock, formatTimecode, formatTimeFragment, parseTimeFragment } from '@/media/videoTime';

interface VideoToolbarProps {
  playback: VideoPlayback;
  /** Identifies the video for the remembered resume position. */
  mediaUlid: string;
  /** Chapters in playback order, for previous/next and the current title. */
  chapters?: readonly VideoChapter[] | undefined;
}

/** How often, at most, the position is saved while the video plays. */
const SAVE_INTERVAL_MS = 5_000;

/**
 * Whether a key press belongs to something else on the page: a text field, or
 * an open dialog sitting over the player.
 */
export function isShortcutBlocked(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) {
    return false;
  }
  if (target.isContentEditable || target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])') !== null) {
    return true;
  }
  return target.closest('[role="dialog"], [role="alertdialog"]') !== null;
}

/**
 * Controls under the video: timecode, frame and second stepping, a link to the
 * current moment, and an offer to resume where the viewer stopped. Native
 * player controls stay in charge of play, volume, and fullscreen.
 */
export function VideoToolbar({ playback, mediaUlid, chapters = [] }: VideoToolbarProps) {
  const state = usePlaybackState(playback);
  const chaptersRef = useRef(chapters);
  useEffect(() => {
    chaptersRef.current = chapters;
  });
  const [resumeAt, setResumeAt] = useState<number | null>(() => (
    parseTimeFragment(window.location.hash) === null ? readResumePosition(mediaUlid) : null
  ));

  // Deep links: #t=… on load, and whenever the fragment changes.
  useEffect(() => {
    const applyFragment = (): void => {
      const seconds = parseTimeFragment(window.location.hash);
      if (seconds !== null) {
        playback.seekWhenReady(seconds);
        setResumeAt(null);
      }
    };
    applyFragment();
    window.addEventListener('hashchange', applyFragment);
    return () => window.removeEventListener('hashchange', applyFragment);
  }, [playback]);

  // Remember the position once the viewer has actually watched or moved it, so
  // merely opening the page at 0:00 never erases a saved position.
  useEffect(() => {
    let engaged = false;
    let lastSaved = 0;
    const save = (): void => {
      if (engaged) {
        const current = playback.getState();
        writeResumePosition(mediaUlid, current.time, current.duration);
      }
    };
    const unsubscribe = playback.subscribe(() => {
      const current = playback.getState();
      if (!current.paused || current.time >= MIN_RESUME_SECONDS) {
        engaged = true;
      }
      if (current.paused) {
        save();
        return;
      }
      const now = Date.now();
      if (now - lastSaved >= SAVE_INTERVAL_MS) {
        lastSaved = now;
        save();
      }
    });
    window.addEventListener('pagehide', save);
    return () => {
      unsubscribe();
      window.removeEventListener('pagehide', save);
      save();
    };
  }, [playback, mediaUlid]);

  // Keyboard: , . step a frame; < > step a second; [ ] previous/next chapter.
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent): void => {
      if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || isShortcutBlocked(event.target)) {
        return;
      }
      switch (event.key) {
        case ',':
          playback.stepFrames(-1);
          break;
        case '.':
          playback.stepFrames(1);
          break;
        case '<':
          playback.stepSeconds(-1);
          break;
        case '>':
          playback.stepSeconds(1);
          break;
        case '[':
        case ']': {
          const time = playback.getState().time;
          const target = event.key === '['
            ? previousChapterStart(chaptersRef.current, time)
            : nextChapterStart(chaptersRef.current, time);
          if (target === null) {
            return;
          }
          playback.seek(target);
          break;
        }
        default:
          return;
      }
      event.preventDefault();
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [playback]);

  const copyLink = async (): Promise<void> => {
    const url = new URL(window.location.href);
    url.hash = `t=${formatTimeFragment(playback.getState().time)}`;
    try {
      await navigator.clipboard.writeText(url.toString());
      toast.success('Link copied.');
    } catch {
      toast.error('Could not copy the link.');
    }
  };

  const resume = (): void => {
    if (resumeAt !== null) {
      // Media Source playback ignores seeks before metadata loads.
      playback.seekWhenReady(resumeAt);
      setResumeAt(null);
    }
  };

  const hasDuration = Number.isFinite(state.duration) && state.duration > 0;
  const currentChapter = chapters[chapterIndexAt(chapters, state.time)];
  const previousTarget = previousChapterStart(chapters, state.time);
  const nextTarget = nextChapterStart(chapters, state.time);
  const frameRateHint = state.frameRate === null
    ? 'Frame rate not known yet; frame steps assume 30 fps.'
    : `${formatFrameRate(state.frameRate)} fps`;

  return (
    <div className="flex flex-wrap items-center gap-2" aria-label="Video controls" role="toolbar">
      <span className="font-mono text-sm tabular-nums" title={frameRateHint}>
        {formatTimecode(state.time, state.frameRate)}
        {hasDuration && <span className="text-muted-foreground"> / {formatClock(state.duration)}</span>}
      </span>
      <div className="flex items-center gap-1">
        <Button type="button" size="icon-sm" variant="outline" onClick={() => playback.stepSeconds(-1)} aria-label="Back one second" aria-keyshortcuts="<" title="Back one second (<)">
          <ChevronsLeft aria-hidden="true" />
        </Button>
        <Button type="button" size="icon-sm" variant="outline" onClick={() => playback.stepFrames(-1)} aria-label="Previous frame" aria-keyshortcuts="," title="Previous frame (,)">
          <ChevronLeft aria-hidden="true" />
        </Button>
        <Button type="button" size="icon-sm" variant="outline" onClick={() => playback.stepFrames(1)} aria-label="Next frame" aria-keyshortcuts="." title="Next frame (.)">
          <ChevronRight aria-hidden="true" />
        </Button>
        <Button type="button" size="icon-sm" variant="outline" onClick={() => playback.stepSeconds(1)} aria-label="Forward one second" aria-keyshortcuts=">" title="Forward one second (>)">
          <ChevronsRight aria-hidden="true" />
        </Button>
      </div>
      {chapters.length > 0 && (
        <div className="flex min-w-0 items-center gap-1">
          <Button type="button" size="icon-sm" variant="outline" onClick={() => previousTarget !== null && playback.seek(previousTarget)} disabled={previousTarget === null} aria-label="Previous chapter" aria-keyshortcuts="[" title="Previous chapter ([)">
            <SkipBack aria-hidden="true" />
          </Button>
          <Button type="button" size="icon-sm" variant="outline" onClick={() => nextTarget !== null && playback.seek(nextTarget)} disabled={nextTarget === null} aria-label="Next chapter" aria-keyshortcuts="]" title="Next chapter (])">
            <SkipForward aria-hidden="true" />
          </Button>
          {currentChapter !== undefined && <span className="truncate text-sm" aria-live="polite">{currentChapter.title}</span>}
        </div>
      )}
      <Button type="button" size="sm" variant="ghost" onClick={() => void copyLink()}>
        <LinkIcon aria-hidden="true" /> Copy link at this time
      </Button>
      {resumeAt !== null && state.time < MIN_RESUME_SECONDS && (
        <Button type="button" size="sm" variant="secondary" onClick={resume}>
          <RotateCcw aria-hidden="true" /> Resume at {formatClock(resumeAt)}
        </Button>
      )}
    </div>
  );
}
