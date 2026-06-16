import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { fetchWrapper } from '@/fetchWrapper';
import { MediaPlayer } from '@/media/MediaPlayer';
import { type AdminMediaItem, formatBytes, type ModerationStatusValue, type PagedResponse } from '@/media/types';

type StatusFilter = 'pending' | 'approved' | 'rejected' | 'all';

const FILTERS: StatusFilter[] = ['pending', 'approved', 'rejected', 'all'];

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : 'Request failed.';
}

function badgeClass(status: ModerationStatusValue): string {
  switch (status) {
    case 'approved':
      return 'text-green-700';
    case 'rejected':
      return 'text-destructive';
    default:
      return 'text-amber-600';
  }
}

function AdminMediaPage() {
  const [items, setItems] = useState<AdminMediaItem[]>([]);
  const [filter, setFilter] = useState<StatusFilter>('pending');
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [notes, setNotes] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState<Record<number, boolean>>({});

  // page 1 replaces the list (filter change / after a moderation action); higher
  // pages append for the "Load more" control.
  const load = async (next: StatusFilter, nextPage = 1): Promise<void> => {
    if (nextPage > 1) {
      setLoadingMore(true);
    } else {
      setLoading(true);
    }
    try {
      const params = new URLSearchParams({ page: String(nextPage) });
      if (next !== 'all') {
        params.set('status', next);
      }
      const response = (await fetchWrapper.get(`/api/admin/media?${params.toString()}`)) as PagedResponse<AdminMediaItem>;
      const rows = response.data ?? [];
      setItems((current) => (nextPage > 1 ? [...current, ...rows] : rows));
      setHasMore(response.meta?.has_more ?? false);
      setPage(nextPage);
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setLoading(false);
      setLoadingMore(false);
    }
  };

  useEffect(() => {
    void load(filter);
  }, [filter]);

  const moderate = async (item: AdminMediaItem, action: 'approve' | 'reject'): Promise<void> => {
    setBusy((current) => ({ ...current, [item.id]: true }));
    try {
      await fetchWrapper.post(`/api/admin/media/${item.id}/moderate`, {
        action,
        notes: notes[item.id]?.trim() || null,
      });
      toast.success(`Media ${action === 'approve' ? 'approved' : 'rejected'}.`);
      await load(filter);
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setBusy((current) => ({ ...current, [item.id]: false }));
    }
  };

  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <h1 className="mb-2 text-2xl font-bold">Media review</h1>
      <p className="mb-6 text-muted-foreground">
        Review uploaded photos and videos for TOS/AUP compliance. Uploaders cannot see this review status.
      </p>

      <div className="mb-6 flex gap-2">
        {FILTERS.map((value) => (
          <Button
            key={value}
            type="button"
            size="sm"
            variant={filter === value ? 'default' : 'outline'}
            onClick={() => setFilter(value)}
          >
            {value}
          </Button>
        ))}
      </div>

      {loading ? (
        <p className="text-muted-foreground">Loading…</p>
      ) : items.length === 0 ? (
        <p className="text-muted-foreground">Nothing to review here.</p>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {items.map((item) => (
            <Card key={item.id}>
              <CardHeader>
                <CardTitle className="flex items-center justify-between text-base">
                  <span className="truncate">{item.title || item.original_filename}</span>
                  <span className={`text-xs uppercase ${badgeClass(item.moderation_status)}`}>
                    {item.moderation_status}
                  </span>
                </CardTitle>
              </CardHeader>
              <CardContent className="grid gap-3">
                <div className="overflow-hidden rounded-md bg-muted">
                  <MediaPlayer item={item} className="max-h-64 w-full object-contain" />
                </div>
                {item.thumbnail_url && (
                  <div className="grid gap-1">
                    <span className="text-xs text-muted-foreground">
                      Thumbnail/poster (shown in listings — review this too)
                    </span>
                    <div className="overflow-hidden rounded-md bg-muted">
                      <img
                        src={item.thumbnail_url}
                        alt={`Thumbnail for ${item.title || item.original_filename}`}
                        className="max-h-40 w-full object-contain"
                      />
                    </div>
                  </div>
                )}
                <dl className="text-xs text-muted-foreground">
                  <div className="flex justify-between">
                    <dt>Uploader</dt>
                    <dd>{item.user.name} ({item.user.email})</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt>Type / size</dt>
                    <dd>{item.type} · {formatBytes(item.size_bytes)}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt>Audience</dt>
                    <dd>{item.audience}{item.discoverable ? '' : ' · link-only'}</dd>
                  </div>
                </dl>
                {item.interests.length > 0 && (
                  <p className="text-xs text-muted-foreground">{item.interests.map((i) => i.name).join(', ')}</p>
                )}
                {item.moderation_notes && (
                  <p className="text-xs">Notes: {item.moderation_notes}</p>
                )}
                <Input
                  value={notes[item.id] ?? ''}
                  onChange={(event) => setNotes((current) => ({ ...current, [item.id]: event.target.value }))}
                  placeholder="Review notes (optional)"
                />
                <div className="flex gap-2">
                  <Button type="button" size="sm" disabled={busy[item.id]} onClick={() => void moderate(item, 'approve')}>
                    Approve
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    disabled={busy[item.id]}
                    onClick={() => void moderate(item, 'reject')}
                  >
                    Reject
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
      {hasMore && (
        <div className="mt-6 flex justify-center">
          <Button type="button" variant="outline" disabled={loadingMore} onClick={() => void load(filter, page + 1)}>
            {loadingMore ? 'Loading…' : 'Load more'}
          </Button>
        </div>
      )}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('admin-media');
if (mountEl) {
  createRoot(mountEl).render(<AdminMediaPage />);
}
