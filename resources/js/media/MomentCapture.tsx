import { Camera } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { captureFrame, MAX_MOMENT_CAPTION_LENGTH, saveMoment } from '@/media/moments';
import type { VideoMoment } from '@/media/types';
import { usePlaybackState, type VideoPlayback } from '@/media/videoPlayback';
import { formatTimecode } from '@/media/videoTime';

interface MomentCaptureProps {
  mediaUlid: string;
  playback: VideoPlayback;
  onSaved: (moment: VideoMoment, localPreview: string | null) => void;
  onClose: () => void;
}

/**
 * The capture tray: pauses the video and locks it to the top quality, so the
 * frame tuned with the toolbar's frame steps (, and .) is the one saved.
 */
export function MomentCapture({ mediaUlid, playback, onSaved, onClose }: MomentCaptureProps) {
  const state = usePlaybackState(playback);
  const [caption, setCaption] = useState('');
  const [visibility, setVisibility] = useState<VideoMoment['visibility']>('shared');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const captionId = useId();

  useEffect(() => {
    playback.video.pause();
    playback.pinTopQuality();
    return () => playback.releaseQuality();
  }, [playback]);

  const save = async (): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const capture = await captureFrame(playback.video);
      const moment = await saveMoment(mediaUlid, {
        // The presented frame's timestamp, floored: the frame's own name.
        time_ms: Math.max(0, Math.floor(playback.getState().time * 1000)),
        caption: caption.trim() === '' ? null : caption.trim(),
        visibility,
      }, capture);
      onSaved(moment, capture === null ? null : URL.createObjectURL(capture));
    } catch (err) {
      setError(err instanceof Error ? err.message : typeof err === 'string' ? err : 'Could not save the moment.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <section aria-labelledby="moment-capture-heading" className="space-y-3 rounded-lg border border-border p-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="moment-capture-heading" className="text-base font-semibold">Save a moment</h2>
        <span className="font-mono text-sm tabular-nums">{formatTimecode(state.time, state.frameRate)}</span>
      </div>
      <p className="text-sm text-muted-foreground">Use , and . to pick the exact frame, then save it.</p>
      <div className="space-y-1">
        <Label htmlFor={captionId}>Caption (optional)</Label>
        <Input id={captionId} value={caption} maxLength={MAX_MOMENT_CAPTION_LENGTH} onChange={(event) => setCaption(event.target.value)} disabled={busy} />
      </div>
      <fieldset className="space-y-1 text-sm">
        <legend className="mb-1 font-medium">Who can see it</legend>
        <label className="flex items-center gap-2">
          <input type="radio" name="moment-visibility" checked={visibility === 'shared'} onChange={() => setVisibility('shared')} disabled={busy} />
          Everyone who can see this video
        </label>
        <label className="flex items-center gap-2">
          <input type="radio" name="moment-visibility" checked={visibility === 'private'} onChange={() => setVisibility('private')} disabled={busy} />
          Only me
        </label>
      </fieldset>
      {error !== null && <p className="text-sm text-destructive" role="alert">{error}</p>}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button>
        <Button type="button" onClick={() => void save()} disabled={busy}>
          <Camera aria-hidden="true" /> {busy ? 'Saving…' : 'Save moment'}
        </Button>
      </div>
    </section>
  );
}
