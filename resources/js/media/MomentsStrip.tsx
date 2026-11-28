import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { deleteMoment, MAX_MOMENT_CAPTION_LENGTH, retryMoment, setMomentAsCover, updateMoment } from '@/media/moments';
import type { VideoMoment } from '@/media/types';
import type { VideoPlayback } from '@/media/videoPlayback';
import { formatClock, formatTimeFragment } from '@/media/videoTime';

interface MomentsStripProps {
  mediaUlid: string;
  playback: VideoPlayback;
  moments: readonly VideoMoment[];
  /** Object URLs of captures made in this page, shown to their author until the real frame loads. */
  localPreviews: ReadonlyMap<string, string>;
  onChange: (moment: VideoMoment) => void;
  onRemove: (ulid: string) => void;
  /** Present for the uploader: turn a moment into a chapter start. */
  onMakeChapter?: ((moment: VideoMoment) => void) | undefined;
}

/** Seek a millisecond into the frame a moment names, so that frame shows. */
function momentSeconds(moment: VideoMoment): number {
  return (moment.time_ms + 1) / 1000;
}

function errorMessage(err: unknown): string {
  return err instanceof Error ? err.message : typeof err === 'string' ? err : 'Something went wrong.';
}

/**
 * Saved frames of the video as a horizontal strip, with a detail dialog for
 * the full frame and its actions. Another viewer's frame shows as "being
 * prepared" until the server has extracted it; only the author sees their own
 * browser capture in the meantime.
 */
export function MomentsStrip({ mediaUlid, playback, moments, localPreviews, onChange, onRemove, onMakeChapter }: MomentsStripProps) {
  const [filter, setFilter] = useState<'everyone' | 'mine'>('everyone');
  const [openUlid, setOpenUlid] = useState<string | null>(null);
  const [captionDraft, setCaptionDraft] = useState('');
  const [busy, setBusy] = useState(false);

  const shown = filter === 'mine' ? moments.filter((moment) => moment.is_mine) : moments;
  const open = moments.find((moment) => moment.ulid === openUlid) ?? null;

  const imageFor = (moment: VideoMoment, full: boolean): string | null => {
    if (moment.image !== null && !moment.image.provisional) {
      return full ? moment.image.full_url : moment.image.thumb_url;
    }
    return localPreviews.get(moment.ulid) ?? (moment.image === null ? null : full ? moment.image.full_url : moment.image.thumb_url);
  };

  const run = async (action: () => Promise<void>): Promise<void> => {
    setBusy(true);
    try {
      await action();
    } catch (err) {
      toast.error(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const openMoment = (moment: VideoMoment): void => {
    setCaptionDraft(moment.caption ?? '');
    setOpenUlid(moment.ulid);
  };

  if (moments.length === 0) {
    return null;
  }

  return (
    <section aria-labelledby="moments-heading" className="space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id="moments-heading" className="text-lg font-semibold">Moments</h2>
        <div className="flex gap-1" role="group" aria-label="Show moments from">
          <Button type="button" size="sm" variant={filter === 'everyone' ? 'secondary' : 'ghost'} aria-pressed={filter === 'everyone'} onClick={() => setFilter('everyone')}>Everyone</Button>
          <Button type="button" size="sm" variant={filter === 'mine' ? 'secondary' : 'ghost'} aria-pressed={filter === 'mine'} onClick={() => setFilter('mine')}>Mine</Button>
        </div>
      </div>
      {shown.length === 0 ? (
        <p className="text-sm text-muted-foreground">You have not saved any moments of this video.</p>
      ) : (
        <ul className="flex gap-3 overflow-x-auto pb-2">
          {shown.map((moment) => {
            const thumb = imageFor(moment, false);
            const label = formatClock(momentSeconds(moment));
            return (
              <li key={moment.ulid} className="w-44 shrink-0 space-y-1">
                <button type="button" onClick={() => openMoment(moment)} className="block w-full overflow-hidden rounded-md bg-muted" aria-label={`Open the moment at ${label}`}>
                  {thumb !== null ? (
                    <img src={thumb} alt="" loading="lazy" className="aspect-video w-full object-cover" />
                  ) : (
                    <span className="flex aspect-video w-full items-center justify-center px-2 text-center text-xs text-muted-foreground">
                      {moment.frame_status === 'failed' ? 'Frame unavailable' : 'Frame being prepared'}
                    </span>
                  )}
                </button>
                <div className="flex items-baseline justify-between gap-2 text-xs">
                  <button type="button" className="font-mono tabular-nums underline-offset-2 hover:underline" onClick={() => playback.seek(momentSeconds(moment))} aria-label={`Play from ${label}`}>
                    {label}
                  </button>
                  <span className="truncate text-muted-foreground">{moment.is_mine ? 'You' : moment.author?.display_name}</span>
                </div>
                {moment.caption !== null && <p className="line-clamp-2 text-sm">{moment.caption}</p>}
                {moment.visibility === 'private' && <p className="text-xs text-muted-foreground">Only you</p>}
              </li>
            );
          })}
        </ul>
      )}

      <Dialog open={open !== null} onOpenChange={(isOpen) => { if (!isOpen && !busy) setOpenUlid(null); }}>
        {open !== null && (
          <DialogContent className="max-w-3xl">
            <DialogHeader>
              <DialogTitle>Moment at {formatClock(momentSeconds(open))}</DialogTitle>
              <DialogDescription>
                {open.is_mine ? 'Saved by you' : `Saved by ${open.author?.display_name ?? 'someone'}`}
                {open.image?.provisional === true && open.is_mine ? ' · your capture, until the full frame is ready' : ''}
              </DialogDescription>
            </DialogHeader>
            {imageFor(open, true) !== null ? (
              <img src={imageFor(open, true) ?? undefined} alt={open.caption ?? `Frame at ${formatClock(momentSeconds(open))}`} className="max-h-[60svh] w-full rounded-md bg-muted object-contain" />
            ) : (
              <p className="rounded-md bg-muted p-6 text-center text-sm text-muted-foreground">
                {open.frame_status === 'failed' ? 'The frame could not be prepared.' : 'The frame is being prepared.'}
              </p>
            )}
            {open.can_edit ? (
              <div className="flex flex-wrap items-center gap-2">
                <Input aria-label="Caption" value={captionDraft} maxLength={MAX_MOMENT_CAPTION_LENGTH} onChange={(event) => setCaptionDraft(event.target.value)} className="min-w-48 flex-1" disabled={busy} />
                <Button type="button" size="sm" variant="outline" disabled={busy || captionDraft.trim() === (open.caption ?? '')}
                  onClick={() => void run(async () => onChange(await updateMoment(open.ulid, { caption: captionDraft.trim() === '' ? null : captionDraft.trim() })))}>
                  Save caption
                </Button>
                <Button type="button" size="sm" variant="outline" disabled={busy}
                  onClick={() => void run(async () => onChange(await updateMoment(open.ulid, { visibility: open.visibility === 'shared' ? 'private' : 'shared' })))}>
                  {open.visibility === 'shared' ? 'Make private' : 'Share with viewers'}
                </Button>
              </div>
            ) : open.caption !== null && <p className="text-sm">{open.caption}</p>}
            <DialogFooter className="flex flex-wrap gap-2 sm:justify-start">
              <Button type="button" size="sm" onClick={() => { playback.seek(momentSeconds(open)); setOpenUlid(null); }}>Play from here</Button>
              <Button type="button" size="sm" variant="outline" onClick={() => void run(async () => {
                const url = new URL(window.location.href);
                // Keep the millisecond, so the link opens on the moment's own frame.
                url.hash = `t=${formatTimeFragment(momentSeconds(open), { milliseconds: true })}`;
                await navigator.clipboard.writeText(url.toString());
                toast.success('Link copied.');
              })}>Copy link</Button>
              {open.image !== null && !open.image.provisional && (
                <a href={open.image.full_url} download={`moment-${open.time_ms}.jpg`} className="inline-flex h-8 items-center rounded-md border border-border px-3 text-sm">Download</a>
              )}
              {open.is_mine && open.frame_status === 'failed' && (
                <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => void run(async () => onChange(await retryMoment(open.ulid)))}>Try again</Button>
              )}
              {onMakeChapter !== undefined && (
                <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => { onMakeChapter(open); setOpenUlid(null); }}>Start a chapter here</Button>
              )}
              {open.can_use_as_cover && (
                <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => void run(async () => {
                  await setMomentAsCover(mediaUlid, open.ulid);
                  toast.success('Cover updated.');
                })}>Use as cover</Button>
              )}
              {open.can_delete && (
                <Button type="button" size="sm" variant="destructive" disabled={busy} onClick={() => void run(async () => {
                  await deleteMoment(open.ulid);
                  onRemove(open.ulid);
                  setOpenUlid(null);
                  toast.success('Moment removed.');
                })}>Remove</Button>
              )}
            </DialogFooter>
          </DialogContent>
        )}
      </Dialog>
    </section>
  );
}
