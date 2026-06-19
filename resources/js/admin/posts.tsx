import { useCallback, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import type { CommunityPost, PostComment } from '@/community/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { fetchWrapper } from '@/fetchWrapper';
import type { ModerationStatusValue, PageMeta } from '@/media/types';

type StatusFilter = 'pending' | 'approved' | 'rejected' | 'all';
type ReviewTab = 'posts' | 'comments';

interface AdminPost extends CommunityPost {
  moderation_status: ModerationStatusValue;
  moderation_notes: string | null;
}

interface AdminComment extends PostComment {
  post: {
    id: number;
    ulid: string;
    body: string;
    author: { id: number; display_name: string } | null;
  } | null;
  moderation_status: ModerationStatusValue;
  moderation_notes: string | null;
}

interface PagedResponse<T> {
  success: boolean;
  data: T[];
  meta?: PageMeta;
}

const FILTERS: StatusFilter[] = ['pending', 'approved', 'rejected', 'all'];

function badgeVariant(status: ModerationStatusValue): 'secondary' | 'outline' | 'destructive' {
  if (status === 'approved') return 'secondary';
  if (status === 'rejected') return 'destructive';
  return 'outline';
}

function AdminPostsPage() {
  const [tab, setTab] = useState<ReviewTab>('posts');
  const [filter, setFilter] = useState<StatusFilter>('pending');
  const [posts, setPosts] = useState<AdminPost[]>([]);
  const [comments, setComments] = useState<AdminComment[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [notes, setNotes] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<Record<string, boolean>>({});

  const endpoint = tab === 'posts' ? '/api/admin/posts' : '/api/admin/post-comments';

  const load = useCallback(async (nextPage = 1): Promise<void> => {
    if (nextPage > 1) {
      setLoadingMore(true);
    } else {
      setLoading(true);
    }
    try {
      const params = new URLSearchParams({ page: String(nextPage) });
      if (filter !== 'all') params.set('status', filter);
      const response = await fetchWrapper.get(`${endpoint}?${params.toString()}`) as PagedResponse<AdminPost | AdminComment>;
      if (tab === 'posts') {
        const rows = response.data as AdminPost[];
        setPosts((current) => nextPage > 1 ? [...current, ...rows] : rows);
      } else {
        const rows = response.data as AdminComment[];
        setComments((current) => nextPage > 1 ? [...current, ...rows] : rows);
      }
      setPage(nextPage);
      setHasMore(response.meta?.has_more ?? false);
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Could not load moderation queue.');
    } finally {
      setLoading(false);
      setLoadingMore(false);
    }
  }, [endpoint, filter, tab]);

  useEffect(() => {
    void load();
  }, [load]);

  const moderate = async (type: ReviewTab, id: number, action: 'approve' | 'reject'): Promise<void> => {
    const key = `${type}-${id}`;
    setBusy((current) => ({ ...current, [key]: true }));
    try {
      const url = type === 'posts' ? `/api/admin/posts/${id}/moderate` : `/api/admin/post-comments/${id}/moderate`;
      await fetchWrapper.post(url, { action, notes: notes[key]?.trim() || null });
      toast.success(`${type === 'posts' ? 'Post' : 'Comment'} ${action === 'approve' ? 'approved' : 'rejected'}.`);
      await load();
    } catch (err) {
      toast.error(typeof err === 'string' ? err : 'Moderation failed.');
    } finally {
      setBusy((current) => ({ ...current, [key]: false }));
    }
  };

  return (
    <div className="mx-auto max-w-6xl space-y-6 px-4 py-8">
      <div>
        <h1 className="text-2xl font-bold">Post moderation</h1>
        <p className="text-sm text-muted-foreground">Review live community posts and comments.</p>
      </div>
      <div className="flex flex-wrap gap-2">
        <Button type="button" size="sm" variant={tab === 'posts' ? 'default' : 'outline'} onClick={() => setTab('posts')}>Posts</Button>
        <Button type="button" size="sm" variant={tab === 'comments' ? 'default' : 'outline'} onClick={() => setTab('comments')}>Comments</Button>
      </div>
      <div className="flex flex-wrap gap-2">
        {FILTERS.map((value) => (
          <Button key={value} type="button" size="sm" variant={filter === value ? 'default' : 'outline'} onClick={() => setFilter(value)}>
            {value}
          </Button>
        ))}
      </div>
      {loading ? (
        <p className="text-sm text-muted-foreground">Loading...</p>
      ) : tab === 'posts' ? (
        <div className="grid gap-4 md:grid-cols-2">
          {posts.map((post) => {
            const key = `posts-${post.id}`;
            return (
              <Card key={post.id}>
                <CardHeader>
                  <CardTitle className="flex items-center justify-between gap-3 text-base">
                    <span>{post.author?.display_name ?? 'Unknown'}</span>
                    <Badge variant={badgeVariant(post.moderation_status)}>{post.moderation_status}</Badge>
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  <p className="whitespace-pre-wrap text-sm">{post.body}</p>
                  <p className="text-xs text-muted-foreground">{post.audience}{post.discoverable ? '' : ' · unlisted'} · {post.reaction_count} reactions · {post.comment_count} comments</p>
                  {post.attachments.length > 0 && (
                    <p className="text-xs text-muted-foreground">Attachments: {post.attachments.map((attachment) => attachment.label).join(', ')}</p>
                  )}
                  {post.moderation_notes && <p className="text-xs">Notes: {post.moderation_notes}</p>}
                  <Input value={notes[key] ?? ''} onChange={(event) => setNotes((current) => ({ ...current, [key]: event.target.value }))} placeholder="Review notes (optional)" />
                  <div className="flex flex-wrap gap-2">
                    <Button type="button" size="sm" disabled={busy[key]} onClick={() => void moderate('posts', post.id, 'approve')}>Approve</Button>
                    <Button type="button" size="sm" variant="destructive" disabled={busy[key]} onClick={() => void moderate('posts', post.id, 'reject')}>Reject</Button>
                    <Button type="button" size="sm" variant="outline" asChild>
                      <a href={`/p/${post.ulid}`}>Open</a>
                    </Button>
                  </div>
                </CardContent>
              </Card>
            );
          })}
          {posts.length === 0 && <p className="text-sm text-muted-foreground">Nothing to review.</p>}
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {comments.map((comment) => {
            const key = `comments-${comment.id}`;
            return (
              <Card key={comment.id}>
                <CardHeader>
                  <CardTitle className="flex items-center justify-between gap-3 text-base">
                    <span>{comment.author?.display_name ?? 'Unknown'}</span>
                    <Badge variant={badgeVariant(comment.moderation_status)}>{comment.moderation_status}</Badge>
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  <p className="whitespace-pre-wrap text-sm">{comment.body}</p>
                  {comment.post && <p className="line-clamp-2 text-xs text-muted-foreground">On: {comment.post.body}</p>}
                  {comment.moderation_notes && <p className="text-xs">Notes: {comment.moderation_notes}</p>}
                  <Input value={notes[key] ?? ''} onChange={(event) => setNotes((current) => ({ ...current, [key]: event.target.value }))} placeholder="Review notes (optional)" />
                  <div className="flex flex-wrap gap-2">
                    <Button type="button" size="sm" disabled={busy[key]} onClick={() => void moderate('comments', comment.id, 'approve')}>Approve</Button>
                    <Button type="button" size="sm" variant="destructive" disabled={busy[key]} onClick={() => void moderate('comments', comment.id, 'reject')}>Reject</Button>
                    {comment.post && (
                      <Button type="button" size="sm" variant="outline" asChild>
                        <a href={`/p/${comment.post.ulid}`}>Open post</a>
                      </Button>
                    )}
                  </div>
                </CardContent>
              </Card>
            );
          })}
          {comments.length === 0 && <p className="text-sm text-muted-foreground">Nothing to review.</p>}
        </div>
      )}
      {hasMore && (
        <div className="flex justify-center">
          <Button type="button" variant="outline" disabled={loadingMore} onClick={() => void load(page + 1)}>
            {loadingMore ? 'Loading...' : 'Load more'}
          </Button>
        </div>
      )}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('admin-posts');
if (mountEl) createRoot(mountEl).render(<AdminPostsPage />);
