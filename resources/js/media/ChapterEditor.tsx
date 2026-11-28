import { ClipboardPaste, Crosshair, Play, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fetchWrapper } from '@/fetchWrapper';
import { chapterListProblems, MAX_CHAPTER_TITLE_LENGTH, MAX_CHAPTERS, parseChapterList } from '@/media/chapters';
import type { SceneSuggestion, VideoChapter } from '@/media/types';
import { FALLBACK_FRAME_RATE, type VideoPlayback } from '@/media/videoPlayback';
import { formatClock, formatEditTime, parseTimeInput } from '@/media/videoTime';
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

/** Suggestion spacing choices, in seconds. */
const SUGGESTION_GAPS = [5, 10, 20, 60, 120];
/** Suggestions this close to an existing chapter are hidden. */
const SUGGESTION_NEAR_MS = 1000;

/**
 * A suggestion names the first frame of the new shot by the floor of its
 * playback time; one millisecond later is inside that frame, so a chapter
 * there (and a seek to it) shows the new shot rather than the old one.
 */
function suggestionStartMs(suggestion: SceneSuggestion): number {
  return suggestion.ms + 1;
}

interface SuggestionsResponse {
  data: { analysed: boolean; suggestions: SceneSuggestion[] };
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
  const [suggestionGap, setSuggestionGap] = useState(20);
  const [suggestions, setSuggestions] = useState<SceneSuggestion[] | null>(null);
  const [analysed, setAnalysed] = useState(false);
  const [dismissed, setDismissed] = useState<ReadonlySet<number>>(() => new Set());

  useEffect(() => {
    let cancelled = false;
    fetchWrapper.get(`/api/media/by-ulid/${encodeURIComponent(mediaUlid)}/scene-suggestions?min_gap_seconds=${suggestionGap}`)
      .then((response: SuggestionsResponse) => {
        if (!cancelled) {
          setAnalysed(response.data.analysed);
          setSuggestions(response.data.suggestions);
        }
      })
      .catch(() => {
        // Suggestions are optional help; editing works without them.
        if (!cancelled) {
          setSuggestions([]);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [mediaUlid, suggestionGap]);

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

  const visibleSuggestions = (suggestions ?? []).filter((suggestion) => !dismissed.has(suggestion.ms)
    && !draft.some((row) => Math.abs(row.startMs - suggestionStartMs(suggestion)) < SUGGESTION_NEAR_MS));

  const acceptSuggestions = (accepted: readonly SceneSuggestion[]): void => {
    if (accepted.length === 0) {
      return;
    }
    const added: DraftChapter[] = [];
    if (draft.length === 0) {
      added.push({ key: nextKey(), startMs: 0, title: 'Beginning', timeText: formatEditTime(0) });
    }
    for (const suggestion of accepted.slice(0, Math.max(0, MAX_CHAPTERS - draft.length - added.length))) {
      const startMs = suggestionStartMs(suggestion);
      added.push({ key: nextKey(), startMs, title: `Scene at ${formatClock(startMs / 1000)}`, timeText: formatEditTime(startMs) });
    }
    setDraft(sortDraft([...draft, ...added]));
  };

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

      {suggestions !== null && (analysed ? (
        <section aria-labelledby="chapter-suggestions-heading" className="space-y-2 border-t border-border pt-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 id="chapter-suggestions-heading" className="text-sm font-semibold">Suggested starts</h3>
            <div className="flex items-center gap-2">
              <label className="flex items-center gap-1 text-sm text-muted-foreground">
                At least
                <select
                  value={suggestionGap}
                  onChange={(event) => setSuggestionGap(Number(event.target.value))}
                  className="rounded-md border border-input bg-background px-2 py-1 text-sm text-foreground"
                >
                  {SUGGESTION_GAPS.map((gap) => <option key={gap} value={gap}>{gap < 60 ? `${gap} s` : `${gap / 60} min`}</option>)}
                </select>
                apart
              </label>
              <Button type="button" size="sm" variant="outline" onClick={() => acceptSuggestions(visibleSuggestions)} disabled={visibleSuggestions.length === 0}>
                Accept all
              </Button>
            </div>
          </div>
          {visibleSuggestions.length === 0 ? (
            <p className="text-sm text-muted-foreground">No more suggestions at this spacing.</p>
          ) : (
            <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
              {visibleSuggestions.map((suggestion) => {
                const label = formatClock(suggestionStartMs(suggestion) / 1000);
                return (
                  <li key={suggestion.ms} className="space-y-1 rounded-md border border-border p-1.5">
                    {suggestion.thumbnail_url !== null ? (
                      <img src={suggestion.thumbnail_url} alt={`Frame at ${label}`} loading="lazy" className="aspect-video w-full rounded bg-muted object-cover" />
                    ) : (
                      <div className="flex aspect-video w-full items-center justify-center rounded bg-muted text-xs text-muted-foreground">No preview</div>
                    )}
                    <div className="flex items-center justify-between gap-1">
                      <button type="button" className="font-mono text-xs tabular-nums underline-offset-2 hover:underline" onClick={() => playback.seek(suggestionStartMs(suggestion) / 1000)} aria-label={`Play from ${label}`}>
                        {label}{suggestion.kind === 'black' ? ' · after black' : ''}
                      </button>
                      <div className="flex gap-1">
                        <Button type="button" size="sm" variant="outline" onClick={() => acceptSuggestions([suggestion])} aria-label={`Accept the suggestion at ${label}`}>Accept</Button>
                        <Button type="button" size="sm" variant="ghost" onClick={() => setDismissed((current) => new Set(current).add(suggestion.ms))} aria-label={`Dismiss the suggestion at ${label}`}>Dismiss</Button>
                      </div>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </section>
      ) : (
        <p className="text-sm text-muted-foreground">Scene suggestions appear here once the video has been analysed.</p>
      ))}

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
