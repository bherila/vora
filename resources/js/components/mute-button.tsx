import { VolumeX } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { fetchWrapper } from '@/fetchWrapper';

type MutableIdentityType = 'user' | 'character';

interface MuteButtonProps {
  type: MutableIdentityType;
  id: number;
  displayName: string;
  initialMuted: boolean;
  onChanged?: (muted: boolean) => void;
}

/**
 * Exact-identity viewer-side mute toggle. Muting never changes access or follow
 * state; its explanatory copy stays visible on the direct profile so the user
 * understands why this intentional visit still works.
 */
export function MuteButton({
  type,
  id,
  displayName,
  initialMuted,
  onChanged,
}: MuteButtonProps) {
  const [muted, setMuted] = useState(initialMuted);
  const [busy, setBusy] = useState(false);

  const toggle = async (): Promise<void> => {
    const next = !muted;
    setBusy(true);

    try {
      if (next) {
        await fetchWrapper.post('/api/mutes', { type, id });
      } else {
        await fetchWrapper.delete('/api/mutes', { type, id });
      }

      setMuted(next);
      onChanged?.(next);
      toast.success(next ? `${displayName} muted.` : `${displayName} unmuted.`);
    } catch (error) {
      toast.error(typeof error === 'string' ? error : 'Could not update mute.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col items-end gap-1">
      <Button
        type="button"
        variant={muted ? 'secondary' : 'outline'}
        size="sm"
        disabled={busy}
        aria-pressed={muted}
        onClick={() => void toggle()}
      >
        <VolumeX className="h-4 w-4" aria-hidden="true" />
        {busy ? 'Saving…' : muted ? 'Unmute' : 'Mute'}
      </Button>
      {muted && (
        <p className="max-w-56 text-right text-xs text-muted-foreground">
          You won&apos;t see {displayName} in your feed. {displayName} won&apos;t know.
        </p>
      )}
    </div>
  );
}
