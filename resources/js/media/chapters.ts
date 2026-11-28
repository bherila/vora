import type { VideoChapter } from '@/media/types';
import { parseTimeInput } from '@/media/videoTime';

/** Server-side limits, mirrored for immediate feedback (VideoChapter.php). */
export const MAX_CHAPTERS = 200;
export const MAX_CHAPTER_TITLE_LENGTH = 120;

/**
 * Within this many seconds of a chapter's start, "previous" goes to the
 * chapter before; later, it returns to the start of the current one.
 */
export const PREVIOUS_RESTART_SECONDS = 3;

/** Index of the chapter playing at `seconds`, or -1 before the first. */
export function chapterIndexAt(chapters: readonly VideoChapter[], seconds: number): number {
  const ms = seconds * 1000;
  let index = -1;
  for (let i = 0; i < chapters.length; i += 1) {
    const chapter = chapters[i];
    if (chapter !== undefined && chapter.start_ms <= ms + 1) {
      index = i;
    } else {
      break;
    }
  }
  return index;
}

/** Where "previous chapter" should seek to from `seconds`, or null. */
export function previousChapterStart(chapters: readonly VideoChapter[], seconds: number): number | null {
  const index = chapterIndexAt(chapters, seconds);
  const current = chapters[index];
  if (current === undefined) {
    return null;
  }
  if (seconds - current.start_ms / 1000 > PREVIOUS_RESTART_SECONDS || index === 0) {
    return current.start_ms / 1000;
  }
  const previous = chapters[index - 1];
  return previous === undefined ? null : previous.start_ms / 1000;
}

/** Where "next chapter" should seek to from `seconds`, or null at the last. */
export function nextChapterStart(chapters: readonly VideoChapter[], seconds: number): number | null {
  const next = chapters[chapterIndexAt(chapters, seconds) + 1];
  return next === undefined ? null : next.start_ms / 1000;
}

/** End of a chapter in milliseconds: the next start, else the duration. */
export function chapterEndMs(chapters: readonly VideoChapter[], index: number, durationMs: number | null): number | null {
  const next = chapters[index + 1];
  if (next !== undefined) {
    return next.start_ms;
  }
  return durationMs;
}

export interface ParsedChapterList {
  chapters: VideoChapter[];
  /** One message per line that could not be read. */
  errors: string[];
  /** True when a "Beginning" chapter was added at 0:00. */
  addedStart: boolean;
}

// A time, then a dash or whitespace, then the title.
const LIST_LINE = /^\s*((?:\d+:)?\d{1,2}:\d{2}(?:\.\d+)?|\d+(?:\.\d+)?s?)(?:\s*[-–—|]\s*|\s+)(.*\S)\s*$/;

/**
 * Parse pasted lines such as `00:00 Christmas '89` or `12:31 - Birthday`.
 * Blank lines are ignored. Times are sorted; a duplicate time keeps the first
 * title. Without a chapter at 0:00, one named "Beginning" is added.
 */
export function parseChapterList(text: string): ParsedChapterList {
  const errors: string[] = [];
  const byStart = new Map<number, string>();

  text.split(/\r?\n/).forEach((line, index) => {
    if (line.trim() === '') {
      return;
    }
    const match = LIST_LINE.exec(line);
    const seconds = match?.[1] === undefined ? null : parseTimeInput(match[1].replace(/s$/, ''));
    const title = match?.[2]?.trim() ?? '';
    if (seconds === null || title === '') {
      errors.push(`Line ${index + 1}: expected a time and a title, like "12:31 Birthday".`);
      return;
    }
    const startMs = Math.round(seconds * 1000);
    if (!byStart.has(startMs)) {
      byStart.set(startMs, title.slice(0, MAX_CHAPTER_TITLE_LENGTH));
    }
  });

  const chapters = [...byStart.entries()]
    .sort(([a], [b]) => a - b)
    .map(([start_ms, title]) => ({ start_ms, title }));
  const addedStart = chapters.length > 0 && chapters[0]?.start_ms !== 0;
  if (addedStart) {
    chapters.unshift({ start_ms: 0, title: 'Beginning' });
  }
  return { chapters, errors, addedStart };
}

/**
 * Problems that would make the server reject the list, in the order a person
 * should fix them. Empty when the list can be saved.
 */
export function chapterListProblems(chapters: readonly VideoChapter[], durationMs: number | null): string[] {
  const problems: string[] = [];
  if (chapters.length === 0) {
    return problems;
  }
  if (chapters.length > MAX_CHAPTERS) {
    problems.push(`A video can have at most ${MAX_CHAPTERS} chapters.`);
  }
  if (!chapters.some((chapter) => chapter.start_ms === 0)) {
    problems.push('The first chapter must start at 0:00.');
  }
  const starts = new Set<number>();
  chapters.forEach((chapter, index) => {
    const label = `Chapter ${index + 1}`;
    if (chapter.title.trim() === '') {
      problems.push(`${label} needs a title.`);
    } else if (chapter.title.length > MAX_CHAPTER_TITLE_LENGTH) {
      problems.push(`${label}'s title is longer than ${MAX_CHAPTER_TITLE_LENGTH} characters.`);
    }
    if (starts.has(chapter.start_ms)) {
      problems.push(`${label} starts at the same time as another chapter.`);
    }
    starts.add(chapter.start_ms);
    if (durationMs !== null && chapter.start_ms >= durationMs) {
      problems.push(`${label} starts after the end of the video.`);
    }
  });
  return problems;
}
