import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { fetchWrapper } from '@/fetchWrapper';
import { InterestRatingList, type RatableInterest } from '@/interests/interest-rating-list';

interface CharacterInterestsEditorProps {
  characterId: number;
  initialInherit: boolean;
  onInheritChange?: (inherit: boolean) => void;
}

interface InterestsResponse {
  success: boolean;
  inherit_interests: boolean;
  data: RatableInterest[];
}

interface RatingSnapshot {
  id: number;
  level: number;
}

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : 'Request failed.';
}

/**
 * Collapsible per-character interest editor. A character inherits the owner's
 * profile interests by default; switching to "Set custom interests" reveals the
 * rating sliders. Switching back to inherit deletes the overrides immediately
 * but snapshots them in memory so the change can be undone.
 */
export function CharacterInterestsEditor({ characterId, initialInherit, onInheritChange }: CharacterInterestsEditorProps) {
  const [open, setOpen] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [loading, setLoading] = useState(false);
  const [inherit, setInherit] = useState(initialInherit);
  const [interests, setInterests] = useState<RatableInterest[]>([]);
  const [busy, setBusy] = useState(false);
  const [undoSnapshot, setUndoSnapshot] = useState<RatingSnapshot[] | null>(null);

  const applyInherit = (value: boolean): void => {
    setInherit(value);
    onInheritChange?.(value);
  };

  useEffect(() => {
    if (!open || loaded) {
      return;
    }

    let active = true;
    const load = async (): Promise<void> => {
      setLoading(true);
      try {
        const response = await fetchWrapper.get(`/api/characters/${characterId}/interests`) as InterestsResponse;
        if (active) {
          setInterests(response.data);
          setInherit(response.inherit_interests);
          setLoaded(true);
        }
      } catch (err) {
        toast.error(getErrorMessage(err));
      } finally {
        if (active) {
          setLoading(false);
        }
      }
    };

    void load();

    return () => {
      active = false;
    };
  }, [open, loaded, characterId]);

  const handleSave = async (interestId: number, level: number): Promise<void> => {
    try {
      await fetchWrapper.post(`/api/characters/${characterId}/interests/${interestId}/rate`, { level });
      setInterests((current) => current.map((item) => (item.id === interestId ? { ...item, rating: level } : item)));
      applyInherit(false);
    } catch (err) {
      toast.error(getErrorMessage(err));
    }
  };

  const handleClear = async (interestId: number): Promise<void> => {
    try {
      await fetchWrapper.delete(`/api/characters/${characterId}/interests/${interestId}/rate`, {});
      setInterests((current) => current.map((item) => (item.id === interestId ? { ...item, rating: null } : item)));
    } catch (err) {
      toast.error(getErrorMessage(err));
    }
  };

  const switchToInherit = async (): Promise<void> => {
    const snapshot: RatingSnapshot[] = interests
      .filter((item) => item.rating !== null)
      .map((item) => ({ id: item.id, level: item.rating as number }));

    setBusy(true);
    try {
      await fetchWrapper.post(`/api/characters/${characterId}/interests/inherit`, { inherit: true });
      setInterests((current) => current.map((item) => ({ ...item, rating: null })));
      setUndoSnapshot(snapshot.length > 0 ? snapshot : null);
      applyInherit(true);
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const switchToOverride = async (): Promise<void> => {
    setBusy(true);
    try {
      await fetchWrapper.post(`/api/characters/${characterId}/interests/inherit`, { inherit: false });
      setUndoSnapshot(null);
      applyInherit(false);
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const undoInherit = async (): Promise<void> => {
    if (!undoSnapshot) {
      return;
    }

    setBusy(true);
    try {
      await fetchWrapper.post(`/api/characters/${characterId}/interests/inherit`, { inherit: false });
      for (const { id, level } of undoSnapshot) {
        await fetchWrapper.post(`/api/characters/${characterId}/interests/${id}/rate`, { level });
      }
      setInterests((current) => current.map((item) => {
        const restored = undoSnapshot.find((entry) => entry.id === item.id);
        return restored ? { ...item, rating: restored.level } : item;
      }));
      setUndoSnapshot(null);
      applyInherit(false);
      toast.success('Restored custom interests.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-3">
      <Button type="button" variant="secondary" size="sm" onClick={() => setOpen((value) => !value)}>
        {open ? 'Hide interests' : 'Manage interests'}
      </Button>

      {open && (
        <div className="space-y-3 rounded-md border border-border p-3">
          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              size="sm"
              variant={inherit ? 'default' : 'outline'}
              disabled={busy || inherit}
              onClick={() => void switchToInherit()}
            >
              Inherit from my profile
            </Button>
            <Button
              type="button"
              size="sm"
              variant={inherit ? 'outline' : 'default'}
              disabled={busy || !inherit}
              onClick={() => void switchToOverride()}
            >
              Set custom interests
            </Button>
          </div>

          {inherit ? (
            <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
              <span>This character uses your profile interests.</span>
              {undoSnapshot && (
                <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={() => void undoInherit()}>
                  Undo (restore {undoSnapshot.length} custom rating{undoSnapshot.length === 1 ? '' : 's'})
                </Button>
              )}
            </div>
          ) : loading && !loaded ? (
            <p className="text-sm text-muted-foreground">Loading interests…</p>
          ) : (
            <InterestRatingList interests={interests} onSave={handleSave} onClear={handleClear} />
          )}
        </div>
      )}
    </div>
  );
}
