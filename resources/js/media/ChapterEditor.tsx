import { ClipboardPaste, Crosshair, Play, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { chapterListProblems, MAX_CHAPTER_TITLE_LENGTH, MAX_CHAPTERS, parseChapterList } from '@/media/chapters';
import type { VideoChapter } from '@/media/types';
import { FALLBACK_FRAME_RATE, type VideoPlayback } from '@/media/videoPlayback';
import { formatEditTime, parseTimeInput } from '@/media/videoTime';
import { isShortcutBlocked } from '@/media/VideoToolbar';

interface ChapterEditorProps {
  mediaUlid: string;
  chapters: readonly VideoChapter[];
  playback: VideoPlayback;
  durationMs: number | null;
  onSaved: (chapters: VideoChapter[]) => void;
  onCancel: () => void;
}

interface DraftChapter {
  key: number;
  startMs: number;
  title: string;
  /** Text in the time field while it is being edited. */
  timeText: string;
}

// Row keys only need to be unique on the page, so one counter serves every editor.
let lastDraftKey = 0;
function nextKey(): number {
  lastDraftKey += 1;
  return lastDraftKey;
}

function toDraft(chapters: readonly VideoChapter[]): DraftChapter[] {
  return chapters.map((chapter) => ({
    key: nextKey(),
    startMs: chapter.start_ms,
    title: chapter.title,
    timeText: formatEditTime(chapter.start_ms),
  }));
}

function sortDraft(draft: DraftChapter[]): DraftChapter[] {
  return [...draft].sort((a, b) => a.startMs - b.startMs);
}

/**
 * Edit a video's chapters in place beside the player: add one at the
 * playhead (M), nudge starts by a second or a frame, paste a whole list, then
 * save the list as a whole.
 */
export function ChapterEditor({ mediaUlid, chapters, playback, durationMs, onSaved, onCancel }: ChapterEditorProps) {
  const [draft, setDraft] = useState<DraftChapter[]>(() => toDraft(chapters));
  const [pasteOpen, setPasteOpen] = useState(false);
  const [pasteText, setPasteText] = useState('');
  const [pasteMessages, setPasteMessages] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [focusKey, setFocusKey] = useState<number | null>(null);
  const titleRefs = useRef(new Map<number, HTMLInputElement>());

  useEffect(() => {
    if (focusKey !== null) {
      titleRefs.current.get(focusKey)?.focus();
      setFocusKey(null);
    }
  }, [focusKey, draft]);

  const playheadMs = (): number => Math.max(0, Math.floor(playback.getState().time * 1000));
  const frameMs = (): number => Math.round(1000 / (playback.frameRate() ?? FALLBACK_FRAME_RATE));

  const update = (key: number, change: Partial<DraftChapter>): void => {
    setDraft((rows) => rows.map((row) => (row.key === key ? { ...row, ...change } : row)));
  };

  const setStart = (key: number, startMs: number): void => {
    const clamped = Math.max(0, durationMs === null ? startMs : Math.min(startMs, durationMs - 1));
    setDraft((rows) => sortDraft(rows.map((row) => (row.key === key ? { ...row, startMs: clamped, timeText: formatEditTime(clamped) } : row))));
  };

  const addAtPlayhead = (): void => {
    const at = playheadMs();
    const existing = draft.find((row) => row.startMs === at);
    if (existing !== undefined) {
      setFocusKey(existing.key);
      return;
    }
    const added: DraftChapter[] = [];
    if (draft.length === 0 && at > 0) {
      added.push({ key: nextKey(), startMs: 0, title: 'Beginning', timeText: formatEditTime(0) });
    }
    const key = nextKey();
    added.push({ key, startMs: at, title: '', timeText: formatEditTime(at) });
    setDraft(sortDraft([...draft, ...added]));
    setFocusKey(key);
  };

  // M adds a chapter at the playhead while the editor is open.
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent): void => {
      if (event.key.toLowerCase() !== 'm' || event.ctrlKey || event.metaKey || event.altKey || event.defaultPrevented || isShortcutBlocked(event.target)) {
        return;
      }
      event.preventDefault();
      addAtPlayhead();
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  });

  const applyPastedList = (): void => {
    const parsed = parseChapterList(pasteText);
    const messages = [...parsed.errors];
    if (parsed.addedStart) {
      messages.push('Added a "Beginning" chapter at 0:00.');
    }
    if (parsed.chapters.length > 0) {
      setDraft(toDraft(parsed.chapters));
      setPasteOpen(false);
      setPasteText('');
    }
    setPasteMessages(parsed.chapters.length === 0 ? [...messages, 'No chapters found in the list.'] : messages);
  };

  const saved = draft.map((row) => ({ start_ms: row.startMs, title: row.title.trim() }));
  const problems = chapterListProblems(saved, durationMs);
  const unreadTimes = draft.filter((row) => parseTimeInput(row.timeText) === null).length;

  const save = async (): Promise<void> => {
    setBusy(true);
    setSaveError(null);
    try {
      const response = await fetchWrapper.put(`/api/media/by-ulid/${encodeURIComponent(mediaUlid)}/chapters`, { chapters: saved }) as {
        data: VideoChapter[];
      };
      onSaved(response.data);
    } catch (err) {
      setSaveError(err instanceof Error ? err.message : typeof err === 'string' ? err : 'Could not save the chapters.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <section aria-labelledby="chapter-editor-heading" className="space-y-3 rounded-lg border border-border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id="chapter-editor-heading" className="text-base font-semibold">Edit chapters</h2>
        <div className="flex flex-wrap gap-2">
          <Button type="button" size="sm" variant="outline" onClick={addAtPlayhead} disabled={draft.length >= MAX_CHAPTERS} aria-keyshortcuts="M" title="Add a chapter at the playhead (M)">
            <Plus aria-hidden="true" /> Add at playhead
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => setPasteOpen((open) => !open)} aria-expanded={pasteOpen}>
            <ClipboardPaste aria-hidden="true" /> Paste a list
          </Button>
        </div>
      </div>

      {pasteOpen && (
        <div className="space-y-2">
          <Label htmlFor="chapter-paste">One chapter per line, such as "0:00 Christmas '89" or "12:31 - Grandma's birthday".</Label>
          <textarea
            id="chapter-paste"
            value={pasteText}
            onChange={(event) => setPasteText(event.target.value)}
            rows={6}
            className="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm"
          />
          <div className="flex gap-2">
            <Button type="button" size="sm" onClick={applyPastedList} disabled={pasteText.trim() === ''}>Replace with this list</Button>
            <Button type="button" size="sm" variant="ghost" onClick={() => setPasteOpen(false)}>Close</Button>
          </div>
        </div>
      )}
      {pasteMessages.length > 0 && (
        <ul className="space-y-1 text-sm text-muted-foreground" aria-live="polite">
          {pasteMessages.map((message) => <li key={message}>{message}</li>)}
        </ul>
      )}

      {draft.length === 0 ? (
        <p className="text-sm text-muted-foreground">No chapters yet. Play to a moment and press "Add at playhead", or paste a list.</p>
      ) : (
        <ol className="space-y-2">
          {draft.map((row, index) => {
            const isFirst = index === 0;
            const timeInvalid = parseTimeInput(row.timeText) === null;
            return (
              <li key={row.key} className="flex flex-wrap items-center gap-2">
                <Input
                  aria-label={`Chapter ${index + 1} start time`}
                  aria-invalid={timeInvalid || undefined}
                  value={row.timeText}
                  disabled={isFirst && row.startMs === 0}
                  onChange={(event) => update(row.key, { timeText: event.target.value })}
                  onBlur={() => {
                    const seconds = parseTimeInput(row.timeText);
                    if (seconds !== null) {
                      setStart(row.key, Math.round(seconds * 1000));
                    }
                  }}
                  className="w-28 font-mono tabular-nums"
                />
                <Input
                  ref={(element) => {
                    if (element === null) {
                      titleRefs.current.delete(row.key);
                    } else {
                      titleRefs.current.set(row.key, element);
                    }
                  }}
                  aria-label={`Chapter ${index + 1} title`}
                  value={row.title}
                  maxLength={MAX_CHAPTER_TITLE_LENGTH}
                  placeholder="Title"
                  onChange={(event) => update(row.key, { title: event.target.value })}
                  className="min-w-40 flex-1"
                />
                {!(isFirst && row.startMs === 0) && (
                  <div className="flex items-center gap-1">
                    <Button type="button" size="sm" variant="outline" onClick={() => setStart(row.key, row.startMs - 1000)} aria-label={`Chapter ${index + 1}: one second earlier`}>−1s</Button>
                    <Button type="button" size="sm" variant="outline" onClick={() => setStart(row.key, row.startMs - frameMs())} aria-label={`Chapter ${index + 1}: one frame earlier`}>−1f</Button>
                    <Button type="button" size="sm" variant="outline" onClick={() => setStart(row.key, row.startMs + frameMs())} aria-label={`Chapter ${index + 1}: one frame later`}>+1f</Button>
                    <Button type="button" size="sm" variant="outline" onClick={() => setStart(row.key, row.startMs + 1000)} aria-label={`Chapter ${index + 1}: one second later`}>+1s</Button>
                    <Button type="button" size="icon-sm" variant="outline" onClick={() => setStart(row.key, playheadMs())} aria-label={`Chapter ${index + 1}: set to playhead`} title="Set to playhead">
                      <Crosshair aria-hidden="true" />
                    </Button>
                  </div>
                )}
                <Button type="button" size="icon-sm" variant="ghost" onClick={() => playback.seek(row.startMs / 1000)} aria-label={`Play from chapter ${index + 1}`} title="Play from here">
                  <Play aria-hidden="true" />
                </Button>
                <Button
                  type="button"
                  size="icon-sm"
                  variant="ghost"
                  onClick={() => setDraft((rows) => rows.filter((candidate) => candidate.key !== row.key))}
                  disabled={isFirst && draft.length > 1}
                  aria-label={`Delete chapter ${index + 1}`}
                  title={isFirst && draft.length > 1 ? 'The first chapter starts the video' : 'Delete'}
                >
                  <Trash2 aria-hidden="true" />
                </Button>
              </li>
            );
          })}
        </ol>
      )}

      {(problems.length > 0 || unreadTimes > 0 || saveError !== null) && (
        <ul className="space-y-1 text-sm text-destructive" aria-live="polite">
          {unreadTimes > 0 && <li>Use times like 12:31 or 1:02:30.</li>}
          {problems.map((problem) => <li key={problem}>{problem}</li>)}
          {saveError !== null && <li>{saveError}</li>}
        </ul>
      )}

      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onCancel} disabled={busy}>Cancel</Button>
        <Button type="button" onClick={() => void save()} disabled={busy || problems.length > 0 || unreadTimes > 0}>
          {busy ? 'Saving…' : 'Save chapters'}
        </Button>
      </div>
    </section>
  );
}
