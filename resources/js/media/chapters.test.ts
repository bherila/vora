import { chapterEndMs, chapterIndexAt, chapterListProblems, MAX_CHAPTERS, nextChapterStart, parseChapterList, previousChapterStart } from '@/media/chapters';
import type { VideoChapter } from '@/media/types';

const chapters: VideoChapter[] = [
  { start_ms: 0, title: 'Christmas' },
  { start_ms: 60_000, title: 'Birthday' },
  { start_ms: 120_000, title: 'Beach' },
];

describe('chapter navigation', () => {
  it('finds the playing chapter', () => {
    expect(chapterIndexAt(chapters, 0)).toBe(0);
    expect(chapterIndexAt(chapters, 59.9)).toBe(0);
    expect(chapterIndexAt(chapters, 60)).toBe(1);
    expect(chapterIndexAt(chapters, 500)).toBe(2);
    expect(chapterIndexAt([{ start_ms: 5000, title: 'Late' }], 1)).toBe(-1);
    expect(chapterIndexAt([], 10)).toBe(-1);
  });

  it('goes back to the chapter start, or to the previous chapter near its start', () => {
    expect(previousChapterStart(chapters, 90)).toBe(60);
    expect(previousChapterStart(chapters, 61)).toBe(0);
    expect(previousChapterStart(chapters, 1)).toBe(0);
    expect(previousChapterStart([], 10)).toBeNull();
  });

  it('goes forward to the next chapter until the last', () => {
    expect(nextChapterStart(chapters, 0)).toBe(60);
    expect(nextChapterStart(chapters, 119)).toBe(120);
    expect(nextChapterStart(chapters, 130)).toBeNull();
  });

  it('ends a chapter at the next start or the duration', () => {
    expect(chapterEndMs(chapters, 0, 300_000)).toBe(60_000);
    expect(chapterEndMs(chapters, 2, 300_000)).toBe(300_000);
    expect(chapterEndMs(chapters, 2, null)).toBeNull();
  });
});

describe('parseChapterList', () => {
  it('reads common list formats, sorted, keeping the first title per time', () => {
    const parsed = parseChapterList([
      "12:31 - Grandma's birthday",
      '',
      "0:00 Christmas '89",
      '1:02:30 — Beach | day two',
      '12:31 Duplicate',
      '90s Ninety',
    ].join('\n'));

    expect(parsed.errors).toEqual([]);
    expect(parsed.addedStart).toBe(false);
    expect(parsed.chapters).toEqual([
      { start_ms: 0, title: "Christmas '89" },
      { start_ms: 90_000, title: 'Ninety' },
      { start_ms: 751_000, title: "Grandma's birthday" },
      { start_ms: 3_750_000, title: 'Beach | day two' },
    ]);
  });

  it('adds a beginning chapter and reports unreadable lines', () => {
    const parsed = parseChapterList('5:00 Party\nno time here\n6:00');

    expect(parsed.addedStart).toBe(true);
    expect(parsed.chapters).toEqual([{ start_ms: 0, title: 'Beginning' }, { start_ms: 300_000, title: 'Party' }]);
    expect(parsed.errors).toEqual([
      'Line 2: expected a time and a title, like "12:31 Birthday".',
      'Line 3: expected a time and a title, like "12:31 Birthday".',
    ]);
    expect(parseChapterList('').chapters).toEqual([]);
  });

  it('truncates long titles to the limit', () => {
    expect(parseChapterList(`0:00 ${'x'.repeat(200)}`).chapters[0]?.title).toHaveLength(120);
  });
});

describe('chapterListProblems', () => {
  it('accepts a valid list and an empty one', () => {
    expect(chapterListProblems(chapters, 300_000)).toEqual([]);
    expect(chapterListProblems([], 300_000)).toEqual([]);
  });

  it('reports what the server would reject', () => {
    expect(chapterListProblems([{ start_ms: 1000, title: 'Late' }], null)).toEqual(['The first chapter must start at 0:00.']);
    expect(chapterListProblems([{ start_ms: 0, title: ' ' }, { start_ms: 0, title: 'Again' }], null)).toEqual([
      'Chapter 1 needs a title.',
      'Chapter 2 starts at the same time as another chapter.',
    ]);
    expect(chapterListProblems([{ start_ms: 0, title: 'A' }, { start_ms: 60_000, title: 'B' }], 60_000)).toEqual([
      'Chapter 2 starts after the end of the video.',
    ]);
    const tooMany = Array.from({ length: MAX_CHAPTERS + 1 }, (_, i) => ({ start_ms: i * 1000, title: `C${i}` }));
    expect(chapterListProblems(tooMany, null)).toContain('A video can have at most 200 chapters.');
  });
});
