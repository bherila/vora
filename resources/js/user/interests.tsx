import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
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

interface TableInterestRow {
  interest: UserInterest;
  depth: number;
}

interface InterestRequestFormState {
  name: string;
  description: string;
  parent_interest_id: string;
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

function flattenInterestTree(nodes: TreeInterest[], depth = 0): TableInterestRow[] {
  const rows: TableInterestRow[] = [];

  for (const node of nodes) {
    rows.push({ interest: { ...node }, depth });
    if (node.children.length > 0) {
      rows.push(...flattenInterestTree(node.children, depth + 1));
    }
  }

  return rows;
}

function UserInterestsPage() {
  const [interests, setInterests] = useState<UserInterest[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState<Record<number, boolean>>({});
  const [error, setError] = useState('');
  const [ratings, setRatings] = useState<Record<number, number>>({});
  const [requestSubmitting, setRequestSubmitting] = useState(false);
  const [requestForm, setRequestForm] = useState<InterestRequestFormState>({
    name: '',
    description: '',
    parent_interest_id: '',
  });

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

  const rows = useMemo<TableInterestRow[]>(() => flattenInterestTree(buildInterestTree(interests)), [interests]);
  const parentOptions = useMemo(() => {
    return interests
      .map((interest) => ({
        id: String(interest.id),
        label: interest.name,
        value: interest.id,
      }))
      .sort((a, b) => a.label.localeCompare(b.label));
  }, [interests]);

  const saveRating = async (event: FormEvent<HTMLFormElement>, id: number): Promise<void> => {
    event.preventDefault();
    const nextRating = ratings[id] ?? 0;

    setSaving((current) => ({ ...current, [id]: true }));
    setError('');
    try {
      await fetchWrapper.post(`/api/interests/${id}/rate`, {
        level: nextRating,
      });

      setInterests((current) => current.map((interest) => (
        interest.id === id
          ? {
            ...interest,
            rating: nextRating,
          }
          : interest
      )));

      toast.success('Interest rating saved.');
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

      setRatings((current) => ({
        ...current,
        [id]: 0,
      }));
      setInterests((current) => current.map((interest) => (
        interest.id === id
          ? {
            ...interest,
            rating: null,
          }
          : interest
      )));

      toast.success('Interest rating cleared.');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setSaving((current) => ({ ...current, [id]: false }));
    }
  };

  const requestNewInterest = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();

    const name = requestForm.name.trim();
    if (!name) {
      setError('Item name is required.');
      return;
    }

    setRequestSubmitting(true);
    setError('');
    try {
      await fetchWrapper.post('/api/interests/request', {
        name,
        description: requestForm.description.trim() || null,
        parent_interest_id: requestForm.parent_interest_id ? Number(requestForm.parent_interest_id) : null,
      });

      setRequestForm({
        name: '',
        description: '',
        parent_interest_id: '',
      });

      toast.success('Interest request submitted for admin review.');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setRequestSubmitting(false);
    }
  };

  return (
    <div className="mx-auto max-w-5xl px-4 py-8">
      <h1 className="mb-4 text-2xl font-bold">Interests</h1>
      <p className="mb-6 text-muted-foreground">
        Browse all interests and rate them on a scale from -10 (fully uninterested) to +10 (fully interested).
      </p>

      {error && (
        <div className="mb-4 rounded border border-destructive bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}

      <Card className="mb-8">
        <CardHeader>
          <CardTitle>Request a new interest</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={(event) => void requestNewInterest(event)} className="grid gap-4">
            <Input
              value={requestForm.name}
              onChange={(event) => setRequestForm((current) => ({ ...current, name: event.target.value }))}
              placeholder="Interest name"
              required
            />
            <Textarea
              value={requestForm.description}
              onChange={(event) => setRequestForm((current) => ({ ...current, description: event.target.value }))}
              placeholder="Description (optional)"
              rows={3}
            />
            <label className="grid gap-1">
              <span className="text-sm">Parent interest (optional)</span>
              <select
                value={requestForm.parent_interest_id}
                onChange={(event) => setRequestForm((current) => ({ ...current, parent_interest_id: event.target.value }))}
                className="w-full rounded-md border border-input bg-background px-3 py-2"
              >
                <option value="">No parent</option>
                {parentOptions.map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
            <Button type="submit" size="sm" className="w-fit" disabled={requestSubmitting}>
              {requestSubmitting ? 'Submitting…' : 'Request item'}
            </Button>
          </form>
        </CardContent>
      </Card>

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
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Interest</TableHead>
                  <TableHead className="w-[460px] text-right">Your rating</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map(({ interest, depth }) => {
                  const rowRating = ratings[interest.id] ?? 0;
                  return (
                    <TableRow key={interest.id}>
                      <TableCell>
                        <div className="space-y-1" style={{ marginLeft: `${depth * 1.25}rem` }}>
                          <p className="font-medium">{interest.name}</p>
                          {interest.description && <p className="text-sm text-muted-foreground">{interest.description}</p>}
                        </div>
                      </TableCell>
                      <TableCell className="text-right">
                        <form
                          onSubmit={(event) => void saveRating(event, interest.id)}
                          className="flex items-center justify-end gap-3"
                        >
                          <label className="grid gap-1 text-left">
                            <span className="text-xs text-muted-foreground">Level (-10 to 10)</span>
                            <Input
                              type="range"
                              min={-10}
                              max={10}
                              value={rowRating}
                              onChange={(event) => setRatings((current) => ({ ...current, [interest.id]: Number(event.target.value) }))}
                              className="w-48"
                            />
                          </label>
                          <Input
                            type="number"
                            min={-10}
                            max={10}
                            value={rowRating}
                            onChange={(event) => setRatings((current) => ({ ...current, [interest.id]: Number(event.target.value) }))}
                            className="w-20"
                          />
                          <Button type="submit" size="sm" disabled={saving[interest.id]}>
                            {saving[interest.id] ? 'Saving…' : 'Save'}
                          </Button>
                          {interest.rating !== null && (
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              onClick={() => void clearRating(interest.id)}
                            >
                              Clear
                            </Button>
                          )}
                        </form>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('user-interests');
if (mountEl) {
  createRoot(mountEl).render(<UserInterestsPage />);
}
