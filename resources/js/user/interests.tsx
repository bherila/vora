import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { fetchWrapper } from '@/fetchWrapper';

interface UserInterest {
  id: number;
  name: string;
  description: string | null;
  parent_interest_id: number | null;
  rating: number | null;
}

interface TreeInterest extends UserInterest {
  children: TreeInterest[];
}

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : 'Request failed.';
}

function buildInterestTree(interests: UserInterest[]): TreeInterest[] {
  const map = new Map<number, TreeInterest>();
  const roots: TreeInterest[] = [];

  for (const interest of interests) {
    map.set(interest.id, {
      ...interest,
      children: [],
    });
  }

  for (const node of map.values()) {
    if (node.parent_interest_id !== null) {
      const parent = map.get(node.parent_interest_id);
      if (parent) {
        parent.children.push(node);
        continue;
      }
    }
    roots.push(node);
  }

  for (const node of map.values()) {
    node.children.sort((a, b) => a.name.localeCompare(b.name));
  }
  roots.sort((a, b) => a.name.localeCompare(b.name));
  return roots;
}

function UserInterestsPage() {
  const [interests, setInterests] = useState<UserInterest[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState<Record<number, boolean>>({});
  const [error, setError] = useState('');
  const [ratings, setRatings] = useState<Record<number, number>>({});

  const loadInterests = async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const response = await fetchWrapper.get('/api/interests') as { success: boolean; data: UserInterest[] };
      setInterests(response.data ?? []);
      const nextRatings: Record<number, number> = {};
      for (const interest of response.data) {
        nextRatings[interest.id] = interest.rating ?? 0;
      }
      setRatings(nextRatings);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadInterests();
  }, []);

  const tree = useMemo<TreeInterest[]>(() => buildInterestTree(interests), [interests]);

  const saveRating = async (event: FormEvent<HTMLFormElement>, id: number): Promise<void> => {
    event.preventDefault();
    setSaving((current) => ({ ...current, [id]: true }));
    setError('');
    try {
      await fetchWrapper.post(`/api/interests/${id}/rate`, {
        level: ratings[id] ?? 0,
      });
      await loadInterests();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSaving((current) => ({ ...current, [id]: false }));
    }
  };

  const clearRating = async (id: number): Promise<void> => {
    setSaving((current) => ({ ...current, [id]: true }));
    setError('');
    try {
      await fetchWrapper.delete(`/api/interests/${id}/rate`, {});
      setRatings((current) => ({ ...current, [id]: 0 }));
      await loadInterests();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSaving((current) => ({ ...current, [id]: false }));
    }
  };

  const renderNode = (node: TreeInterest, depth = 0) => (
    <li key={node.id} className="rounded border border-border bg-background p-3">
      <div className="space-y-2">
        <div className="flex items-center justify-between gap-4">
          <div>
            <p className="font-medium" style={{ marginLeft: `${depth * 1.25}rem` }}>
              {node.name}
            </p>
            {node.description && <p className="text-sm text-muted-foreground">{node.description}</p>}
          </div>
        </div>
        <form onSubmit={(event) => void saveRating(event, node.id)} className="flex flex-wrap items-center gap-3">
          <label className="grid gap-1">
            <span className="text-xs text-muted-foreground">Your level (-10 to 10)</span>
            <Input
              type="range"
              min={-10}
              max={10}
              value={ratings[node.id] ?? 0}
              onChange={(event) => setRatings((current) => ({ ...current, [node.id]: Number(event.target.value) }))}
              className="w-56"
            />
          </label>
          <Input
            type="number"
            min={-10}
            max={10}
            value={ratings[node.id] ?? 0}
            onChange={(event) => setRatings((current) => ({ ...current, [node.id]: Number(event.target.value) }))}
            className="w-20"
          />
          <Button type="submit" size="sm" disabled={saving[node.id]}>
            {saving[node.id] ? 'Saving…' : 'Save'}
          </Button>
          {interests.find((interest) => interest.id === node.id)?.rating !== null && (
            <Button type="button" size="sm" variant="outline" onClick={() => void clearRating(node.id)}>
              Clear
            </Button>
          )}
        </form>
      </div>
      {node.children.length > 0 && (
        <ul className="mt-3 space-y-2 pl-6">
          {node.children.map((child) => renderNode(child, depth + 1))}
        </ul>
      )}
    </li>
  );

  return (
    <div className="mx-auto max-w-4xl px-4 py-8">
      <h1 className="mb-4 text-2xl font-bold">Interests</h1>
      <p className="mb-6 text-muted-foreground">
        Browse all interests and rate them on a scale from -10 (fully uninterested) to +10 (fully interested).
      </p>

      {error && (
        <div className="mb-4 rounded border border-destructive bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}

      {loading ? (
        <p className="text-muted-foreground">Loading interests...</p>
      ) : interests.length === 0 ? (
        <p>No interests are available yet.</p>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>Available Interests</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="space-y-3">
              {tree.map((node) => renderNode(node))}
            </ul>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

const mountEl = document.getElementById('user-interests');
if (mountEl) {
  createRoot(mountEl).render(<UserInterestsPage />);
}
