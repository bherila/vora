import { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { Button } from '@/components/ui/button';
import { readInitialData } from '@/initialData';

import { communityApi } from './api';
import { OnboardingChecklist, type OnboardingSteps } from './OnboardingChecklist';
import { PostCard } from './PostCard';
import { PostComposer } from './PostComposer';
import type { CommunityPost } from './types';

interface FeedInitialData {
  feed?: {
    data?: CommunityPost[];
    next_cursor?: string | null;
  };
  onboarding?: OnboardingSteps | null;
}

function getInitialFeed() {
  const { feed } = readInitialData<FeedInitialData>();
  return {
    posts: feed?.data ?? [],
    nextCursor: feed?.next_cursor ?? null,
  };
}

function FeedPage() {
  const [posts, setPosts] = useState<CommunityPost[]>(() => getInitialFeed().posts);
  const [nextCursor, setNextCursor] = useState<string | null>(() => getInitialFeed().nextCursor);
  const [onboarding] = useState<OnboardingSteps | null>(() => readInitialData<FeedInitialData>().onboarding ?? null);
  const [loading, setLoading] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');

  const sentinelRef = useRef<HTMLDivElement | null>(null);

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

  // Auto-load the next page when the sentinel scrolls into view. The Load more
  // button stays as a fallback (and for when the observer is unavailable).
  useEffect(() => {
    const el = sentinelRef.current;
    if (!el || nextCursor === null) return;

    const observer = new IntersectionObserver((entries) => {
      if (entries[0]?.isIntersecting && !loadingMore) {
        void load(nextCursor);
      }
    }, { rootMargin: '300px' });

    observer.observe(el);
    return () => observer.disconnect();
  }, [nextCursor, loadingMore, load]);

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 py-8">
      <div>
        <h1 className="text-2xl font-bold">Feed</h1>
        <p className="text-sm text-muted-foreground">Posts from you and the people you follow.</p>
      </div>
      {onboarding && <OnboardingChecklist steps={onboarding} />}
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
        <div ref={sentinelRef} className="flex justify-center">
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
