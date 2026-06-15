import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { fetchWrapper } from '@/fetchWrapper';

interface AdminStory {
  id: number;
  ulid: string;
  title: string;
  type: string;
  status: string;
  visibility: string;
  owner: { id: number; display_name: string } | null;
  moderation_status: 'pending' | 'approved' | 'rejected';
  moderation_notes: string | null;
  node_count: number | null;
}

const STATUS_FILTERS = ['pending', 'approved', 'rejected', 'all'] as const;
type StatusFilter = (typeof STATUS_FILTERS)[number];

function badgeClass(status: string): string {
  if (status === 'approved') return 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200';
  if (status === 'rejected') return 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200';
  return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200';
}

function AdminStoriesPage() {
  const [stories, setStories] = useState<AdminStory[]>([]);
  const [filter, setFilter] = useState<StatusFilter>('pending');
  const [error, setError] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = (): void => {
    const query = filter === 'all' ? '' : `?status=${filter}`;
    fetchWrapper
      .get(`/api/admin/stories${query}`)
      .then((r) => setStories((r as { data: AdminStory[] }).data ?? []))
      .catch((e) => setError(typeof e === 'string' ? e : 'Could not load stories.'));
  };
  useEffect(load, [filter]);

  const moderate = async (story: AdminStory, action: 'approve' | 'reject'): Promise<void> => {
    const notes = action === 'reject' ? window.prompt('Optional note to record with this rejection:') ?? undefined : undefined;
    setBusyId(story.id);
    setError('');
    try {
      await fetchWrapper.post(`/api/admin/stories/${story.id}/moderate`, { action, notes });
      load();
    } catch (e) {
      setError(typeof e === 'string' ? e : 'Could not moderate story.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="mx-auto max-w-6xl space-y-6 px-4 py-8">
      <h1 className="text-2xl font-bold">Story review</h1>
      {error && <p className="text-sm text-destructive">{error}</p>}

      <div className="flex flex-wrap gap-2">
        {STATUS_FILTERS.map((f) => (
          <Button key={f} type="button" size="sm" variant={filter === f ? 'default' : 'outline'} onClick={() => setFilter(f)}>
            {f.charAt(0).toUpperCase() + f.slice(1)}
          </Button>
        ))}
      </div>

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Title</TableHead>
            <TableHead>Owner</TableHead>
            <TableHead>Type</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Review</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {stories.length === 0 && (
            <TableRow>
              <TableCell colSpan={6} className="text-sm text-muted-foreground">No stories.</TableCell>
            </TableRow>
          )}
          {stories.map((story) => (
            <TableRow key={story.id}>
              <TableCell>
                <a className="underline underline-offset-4" href={`/s/${story.ulid}`} target="_blank" rel="noopener noreferrer">{story.title}</a>
              </TableCell>
              <TableCell>{story.owner?.display_name ?? '—'}</TableCell>
              <TableCell>{story.type === 'cyoa' ? `Adventure (${story.node_count ?? 0})` : 'Long form'}</TableCell>
              <TableCell>{story.status}</TableCell>
              <TableCell>
                <span className={`rounded px-2 py-0.5 text-xs ${badgeClass(story.moderation_status)}`}>{story.moderation_status}</span>
              </TableCell>
              <TableCell className="text-right">
                <div className="flex justify-end gap-2">
                  <Button type="button" size="sm" disabled={busyId === story.id || story.moderation_status === 'approved'} onClick={() => void moderate(story, 'approve')}>
                    Approve
                  </Button>
                  <Button type="button" size="sm" variant="destructive" disabled={busyId === story.id || story.moderation_status === 'rejected'} onClick={() => void moderate(story, 'reject')}>
                    Reject
                  </Button>
                </div>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

const mount = document.getElementById('admin-stories');
if (mount) createRoot(mount).render(<AdminStoriesPage />);
