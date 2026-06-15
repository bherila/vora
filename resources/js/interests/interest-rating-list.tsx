import { useEffect, useMemo, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { buildInterestTree, flattenInterestTree, getDepthPaddingClass } from '@/interests/tree';

export interface RatableInterest {
  id: number;
  name: string;
  description: string | null;
  parent_interest_id: number | null;
  rating: number | null;
}

interface InterestRatingListProps {
  interests: RatableInterest[];
  /** Persist a rating. Should resolve once the parent has updated `interests`. */
  onSave: (interestId: number, level: number) => Promise<void>;
  /** Remove a rating. Should resolve once the parent has updated `interests`. */
  onClear: (interestId: number) => Promise<void>;
}

/**
 * Hierarchical list of interests with a slider per row. Ratings auto-save when a
 * slider the user actually moved loses focus; a separate Clear removes a rating.
 * Persistence is delegated to the parent via `onSave`/`onClear` so the same UI
 * serves both the user profile and per-character editors.
 */
export function InterestRatingList({ interests, onSave, onClear }: InterestRatingListProps) {
  const rows = useMemo(() => flattenInterestTree(buildInterestTree(interests)), [interests]);
  const [ratings, setRatings] = useState<Record<number, number>>({});
  const [saving, setSaving] = useState<Record<number, boolean>>({});
  // Tracks which sliders the user moved, so a plain focus/blur never persists a
  // rating and an explicit value — including 0 — does.
  const dirty = useRef<Set<number>>(new Set());

  // Keep non-dirty sliders in sync with the saved values (e.g. after a clear).
  useEffect(() => {
    setRatings((current) => {
      const next = { ...current };
      for (const interest of interests) {
        if (!dirty.current.has(interest.id)) {
          next[interest.id] = interest.rating ?? 0;
        }
      }
      return next;
    });
  }, [interests]);

  const saveRating = async (id: number): Promise<void> => {
    const nextRating = ratings[id] ?? 0;
    const serverRating = interests.find((interest) => interest.id === id)?.rating ?? null;
    if (serverRating === nextRating) {
      dirty.current.delete(id);
      return;
    }

    setSaving((current) => ({ ...current, [id]: true }));
    try {
      await onSave(id, nextRating);
      // Clear dirty only after a successful save, so a failed save stays pending
      // and the next blur retries it instead of silently dropping the value.
      dirty.current.delete(id);
    } catch {
      // Leave the row dirty so the unsaved value is preserved for retry.
    } finally {
      setSaving((current) => ({ ...current, [id]: false }));
    }
  };

  const clearRating = async (id: number): Promise<void> => {
    dirty.current.delete(id);
    setSaving((current) => ({ ...current, [id]: true }));
    try {
      await onClear(id);
    } finally {
      setSaving((current) => ({ ...current, [id]: false }));
    }
  };

  if (rows.length === 0) {
    return <p className="text-sm text-muted-foreground">No interests are available yet.</p>;
  }

  return (
    <div className="space-y-2">
      {rows.map((interest) => {
        const rowRating = ratings[interest.id] ?? 0;

        return (
          <div
            key={interest.id}
            className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-md border border-border p-2"
          >
            <div className={getDepthPaddingClass(interest.depth)}>
              <div className="flex items-center gap-2">
                {interest.depth > 0 && <span className="text-muted-foreground">↳</span>}
                <span className="text-sm font-medium">{interest.name}</span>
              </div>
              {interest.description && <p className="mt-0.5 text-xs text-muted-foreground">{interest.description}</p>}
            </div>
            <div className="flex items-center justify-end gap-3">
              <Input
                type="range"
                min={-10}
                max={10}
                value={rowRating}
                aria-label={`Rating for ${interest.name}`}
                onChange={(event) => {
                  dirty.current.add(interest.id);
                  setRatings((current) => ({ ...current, [interest.id]: Number(event.target.value) }));
                }}
                onBlur={() => {
                  if (dirty.current.has(interest.id)) {
                    void saveRating(interest.id);
                  }
                }}
                disabled={saving[interest.id]}
                className="w-40"
              />
              <span className="w-8 text-sm tabular-nums text-muted-foreground">{rowRating}</span>
              {interest.rating !== null && (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  // Keep focus on the slider so its blur-save does not race this click.
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => void clearRating(interest.id)}
                  disabled={saving[interest.id]}
                >
                  Clear
                </Button>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}
