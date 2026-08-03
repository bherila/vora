import { Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { READING_PAGE_WIDTH } from '@/components/page-width';
import { Button } from '@/components/ui/button';
import { fetchWrapper } from '@/fetchWrapper';

type ActivityType = 'posts' | 'comments' | 'replies';

interface ActivityItem {
  ulid: string;
  type: 'post' | 'comment' | 'reply';
  body: string | null;
  status: 'active' | 'rejected' | 'removed_by_post_owner';
  created_at: string | null;
  parent: { ulid: string } | null;
  parent_unavailable?: boolean;
}

interface ActivityResponse {
  data: ActivityItem[];
  next_cursor: string | null;
}

const TYPES: Array<{ value: ActivityType; label: string }> = [
  { value: 'posts', label: 'Posts' },
  { value: 'comments', label: 'Comments' },
  { value: 'replies', label: 'Replies' },
];

function formatDate(value: string | null): string {
  if (value === null) return '';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function statusLabel(status: ActivityItem['status']): string | null {
  if (status === 'rejected') return 'Rejected by moderation';
  if (status === 'removed_by_post_owner') return 'Removed by the post owner';
  return null;
}

export function ActivityPage() {
  const [type, setType] = useState<ActivityType>('posts');
  const [items, setItems] = useState<ActivityItem[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);

  const load = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const response = await fetchWrapper.get(`/api/me/activity?type=${type}`) as ActivityResponse;
      setItems(response.data);
      setCursor(response.next_cursor);
    } catch (error) {
      toast.error(typeof error === 'string' ? error : 'Could not load your activity.');
    } finally {
      setLoading(false);
    }
  }, [type]);

  useEffect(() => { void load(); }, [load]);

  const loadMore = async (): Promise<void> => {
    if (cursor === null) return;
    setLoadingMore(true);
    try {
      const query = `type=${type}&cursor=${encodeURIComponent(cursor)}`;
      const response = await fetchWrapper.get(`/api/me/activity?${query}`) as ActivityResponse;
      setItems((current) => [...current, ...response.data]);
      setCursor(response.next_cursor);
    } catch (error) {
      toast.error(typeof error === 'string' ? error : 'Could not load more activity.');
    } finally {
      setLoadingMore(false);
    }
  };

  const deleteContribution = async (item: ActivityItem): Promise<void> => {
    try {
      await fetchWrapper.delete(`/api/me/activity/comments/${encodeURIComponent(item.ulid)}`);
      setItems((current) => current.filter((candidate) => candidate.ulid !== item.ulid));
      toast.success('Contribution deleted.');
    } catch (error) {
      toast.error(typeof error === 'string' ? error : 'Could not delete this contribution.');
    }
  };

  return (
    <main className={`${READING_PAGE_WIDTH} space-y-6 px-4 py-8`}>
      <header>
        <h1 className="text-2xl font-bold">Your activity</h1>
        <p className="text-muted-foreground">Find and control the posts, comments, and replies you wrote.</p>
      </header>
      <div className="grid grid-cols-3 gap-2" role="group" aria-label="Activity type">
        {TYPES.map((option) => (
          <Button
            key={option.value}
            type="button"
            variant={type === option.value ? 'default' : 'outline'}
            aria-pressed={type === option.value}
            onClick={() => setType(option.value)}
          >
            {option.label}
          </Button>
        ))}
      </div>
      {loading ? (
        <p className="text-sm text-muted-foreground">Loading activity…</p>
      ) : items.length === 0 ? (
        <p className="text-sm text-muted-foreground">Nothing here yet.</p>
      ) : (
        <div className="space-y-3">
          {items.map((item) => {
            const status = statusLabel(item.status);
            return (
              <article key={item.ulid} className="space-y-2 rounded-md border border-border p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="whitespace-pre-wrap text-sm">{item.body ?? 'Post without text.'}</p>
                    <p className="text-xs text-muted-foreground">{formatDate(item.created_at)}</p>
                  </div>
                  {item.type !== 'post' && (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      title="Delete contribution"
                      onClick={() => void deleteContribution(item)}
                    >
                      <Trash2 className="h-4 w-4" aria-hidden="true" />
                    </Button>
                  )}
                </div>
                {status && <p className="text-xs font-medium text-muted-foreground">{status}</p>}
                {item.parent_unavailable || item.parent === null ? (
                  item.type === 'post' ? null : <p className="text-sm text-muted-foreground">Original post unavailable</p>
                ) : (
                  <a className="text-sm underline underline-offset-4" href={`/p/${encodeURIComponent(item.parent.ulid)}`}>
                    View original post
                  </a>
                )}
              </article>
            );
          })}
          {cursor !== null && (
            <Button type="button" variant="outline" className="w-full" disabled={loadingMore} onClick={() => void loadMore()}>
              {loadingMore ? 'Loading…' : 'Load more'}
            </Button>
          )}
        </div>
      )}
      <Toaster position="top-right" richColors closeButton />
    </main>
  );
}

const mount = document.getElementById('your-activity');
if (mount) createRoot(mount).render(<ActivityPage />);
