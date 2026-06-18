import { useCallback, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { Button } from '@/components/ui/button';

import { communityApi } from './api';
import { PostCard } from './PostCard';
import { PostComposer } from './PostComposer';
import type { CommunityPost } from './types';

function FeedPage() {
  const [posts, setPosts] = useState<CommunityPost[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async (cursor: string | null = null): Promise<void> => {
    if (cursor) {
      setLoadingMore(true);
    } else {
      setLoading(true);
    }
    setError('');
    try {
      const response = await communityApi.feed(cursor);
      setPosts((current) => cursor ? [...current, ...response.data] : response.data);
      setNextCursor(response.next_cursor);
    } catch (err) {
      setError(typeof err === 'string' ? err : 'Could not load feed.');
    } finally {
      setLoading(false);
      setLoadingMore(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 py-8">
      <div>
        <h1 className="text-2xl font-bold">Feed</h1>
        <p className="text-sm text-muted-foreground">Posts from you and the people you follow.</p>
      </div>
      <PostComposer onCreated={(post) => setPosts((current) => [post, ...current])} />
      {error && <p className="text-sm text-destructive">{error}</p>}
      {loading ? (
        <p className="text-sm text-muted-foreground">Loading feed...</p>
      ) : posts.length === 0 ? (
        <p className="text-sm text-muted-foreground">No posts yet.</p>
      ) : (
        <div className="space-y-4">
          {posts.map((post) => <PostCard key={post.id} post={post} />)}
        </div>
      )}
      {nextCursor && (
        <div className="flex justify-center">
          <Button type="button" variant="outline" disabled={loadingMore} onClick={() => void load(nextCursor)}>
            {loadingMore ? 'Loading...' : 'Load more'}
          </Button>
        </div>
      )}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('feed');
if (mountEl) createRoot(mountEl).render(<FeedPage />);
