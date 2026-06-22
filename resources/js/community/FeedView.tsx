import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';

import { communityApi } from './api';
import { OnboardingChecklist, type OnboardingSteps } from './OnboardingChecklist';
import { PostCard } from './PostCard';
import { PostComposer } from './PostComposer';
import type { CommunityPost } from './types';

interface FeedViewProps {
  /** First-run checklist state; hidden when null/undefined. */
  onboarding?: OnboardingSteps | null;
}

/**
 * The cross-author timeline (your posts + the people you follow) with a
 * composer. Self-contained: it fetches its first page on mount, so it can be
 * embedded wherever — notably the profile home's Feed tab. The host supplies the
 * page chrome and a Toaster.
 */
export function FeedView({ onboarding = null }: FeedViewProps) {
  const [posts, setPosts] = useState<CommunityPost[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
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

  useEffect(() => {
    void load();
  }, [load]);

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
    <div className="space-y-6">
      {onboarding && <OnboardingChecklist steps={onboarding} />}
      <PostComposer onCreated={(post) => setPosts((current) => [post, ...current])} />
      {error && <p className="text-sm text-destructive">{error}</p>}
      {loading ? (
        <p className="text-sm text-muted-foreground">Loading feed...</p>
      ) : posts.length === 0 ? (
        <p className="text-sm text-muted-foreground">No posts yet. Follow people in Explore or share something above.</p>
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
    </div>
  );
}
