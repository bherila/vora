import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip';

import { communityApi } from './api';
import { OnboardingChecklist, type OnboardingData } from './OnboardingChecklist';
import { PostCard } from './PostCard';
import { PostComposer } from './PostComposer';
import type { CommunityPost, FeedScope } from './types';

interface FeedViewProps {
  /** Whether an accepted account- or persona-scoped follow can populate Following. */
  hasFollowing: boolean;
  /** First-run checklist state; hidden when null/undefined. */
  onboarding?: OnboardingData | null;
}

function scopeFromLocation(): FeedScope {
  return new URLSearchParams(window.location.search).get('scope') === 'mixed'
    ? 'mixed'
    : 'following';
}

function replaceScopeInLocation(scope: FeedScope): void {
  const url = new URL(window.location.href);

  if (scope === 'mixed') {
    url.searchParams.set('scope', scope);
  } else {
    url.searchParams.delete('scope');
  }

  window.history.replaceState(
    window.history.state,
    '',
    `${url.pathname}${url.search}${url.hash}`,
  );
}

/**
 * The cross-author timeline with a composer. Following preserves the focused
 * timeline; Mixed explicitly adds public discovery. The selected scope is kept
 * in the page URL so a reload restores it, while every cursor request carries
 * the same scope independently.
 */
export function FeedView({ hasFollowing, onboarding = null }: FeedViewProps) {
  const followingDisabled = !hasFollowing;
  const [scope, setScope] = useState<FeedScope>(
    () => followingDisabled ? 'mixed' : scopeFromLocation(),
  );
  const [posts, setPosts] = useState<CommunityPost[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');

  const sentinelRef = useRef<HTMLDivElement | null>(null);
  const requestSequenceRef = useRef(0);

  const load = useCallback(async (selectedScope: FeedScope, cursor: string | null = null): Promise<void> => {
    const requestSequence = ++requestSequenceRef.current;
    if (cursor) {
      setLoadingMore(true);
    } else {
      setLoading(true);
    }
    setError('');
    try {
      const response = await communityApi.feed(selectedScope, cursor);
      if (requestSequence !== requestSequenceRef.current) return;

      setPosts((current) => cursor ? [...current, ...response.data] : response.data);
      setNextCursor(response.next_cursor);
    } catch (err) {
      if (requestSequence !== requestSequenceRef.current) return;

      setError(typeof err === 'string' ? err : 'Could not load feed.');
    } finally {
      if (requestSequence === requestSequenceRef.current) {
        setLoading(false);
        setLoadingMore(false);
      }
    }
  }, []);

  useEffect(() => {
    if (followingDisabled) {
      replaceScopeInLocation('mixed');
    }
  }, [followingDisabled]);

  useEffect(() => {
    void load(scope);

    return () => {
      requestSequenceRef.current += 1;
    };
  }, [load, scope]);

  const selectScope = (nextScope: FeedScope): void => {
    if (followingDisabled && nextScope === 'following') return;
    if (nextScope === scope) return;

    // Do not leave the previous membership's posts labelled as the newly
    // selected feed while its first page is in flight.
    requestSequenceRef.current += 1;
    setPosts([]);
    setNextCursor(null);
    setError('');
    setLoading(true);
    replaceScopeInLocation(nextScope);
    setScope(nextScope);
  };

  // Auto-load the next page when the sentinel scrolls into view. The Load more
  // button stays as a fallback (and for when the observer is unavailable).
  useEffect(() => {
    const el = sentinelRef.current;
    if (!el || nextCursor === null) return;

    const observer = new IntersectionObserver((entries) => {
      if (entries[0]?.isIntersecting && !loadingMore) {
        void load(scope, nextCursor);
      }
    }, { rootMargin: '300px' });

    observer.observe(el);
    return () => observer.disconnect();
  }, [nextCursor, loadingMore, load, scope]);

  return (
    <div className="space-y-6">
      {onboarding && <OnboardingChecklist onboarding={onboarding} />}
      <div className="grid gap-2 sm:grid-cols-2" role="group" aria-label="Feed scope">
        <Button
          type="button"
          variant={scope === 'mixed' ? 'default' : 'outline'}
          aria-pressed={scope === 'mixed'}
          className="h-auto w-full justify-start whitespace-normal px-4 py-3 text-left"
          onClick={() => selectScope('mixed')}
        >
          <span>
            <strong>Mixed</strong> — <span className="font-normal">public posts from everyone, plus the people and personas you follow.</span>
          </span>
        </Button>
        {followingDisabled ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <span
                className="block"
                data-testid="following-disabled-tooltip-trigger"
                tabIndex={0}
              >
                <Button
                  type="button"
                  variant="outline"
                  aria-pressed={false}
                  className="h-auto w-full justify-start whitespace-normal px-4 py-3 text-left"
                  disabled
                >
                  <span>
                    <strong>Following</strong> — <span className="font-normal">only the people and personas you follow.</span>
                  </span>
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent role="tooltip" side="top">
              you aren't following anyone yet.
            </TooltipContent>
          </Tooltip>
        ) : (
          <Button
            type="button"
            variant={scope === 'following' ? 'default' : 'outline'}
            aria-pressed={scope === 'following'}
            className="h-auto w-full justify-start whitespace-normal px-4 py-3 text-left"
            onClick={() => selectScope('following')}
          >
            <span>
              <strong>Following</strong> — <span className="font-normal">only the people and personas you follow.</span>
            </span>
          </Button>
        )}
      </div>
      <div className="space-y-2">
        <p className="text-sm text-muted-foreground">
          Every post has an audience. New posts default to Everyone; open Audience, persona &amp; attachments to choose who can see yours.
        </p>
        <PostComposer onCreated={(post) => setPosts((current) => [post, ...current])} />
      </div>
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
          <Button type="button" variant="outline" disabled={loadingMore} onClick={() => void load(scope, nextCursor)}>
            {loadingMore ? 'Loading...' : 'Load more'}
          </Button>
        </div>
      )}
    </div>
  );
}
