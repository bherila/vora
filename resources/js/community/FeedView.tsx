import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { moduleEnabled } from '@/instance';

import { communityApi } from './api';
import { OnboardingChecklist, type OnboardingData } from './OnboardingChecklist';
import { PostCard } from './PostCard';
import { PostComposer } from './PostComposer';
import type { CommunityPost, FeedScope, InterestRef } from './types';

interface FeedViewProps {
  /** Whether an accepted account- or persona-scoped follow can populate Following. */
  hasFollowing: boolean;
  /** First-run checklist state; hidden when null/undefined. */
  onboarding?: OnboardingData | null;
  /** Locked context on a dedicated Interest page. */
  interest?: InterestRef | null;
  /** Selectable contexts on the general feed. */
  interests?: InterestRef[];
  /** Hide the composer when the page already offers one (the first-run page). */
  showComposer?: boolean;
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

function interestFromLocation(): string {
  return new URLSearchParams(window.location.search).get('interest') ?? '';
}

function replaceInterestInLocation(slug: string): void {
  const url = new URL(window.location.href);

  if (slug === '') {
    url.searchParams.delete('interest');
  } else {
    url.searchParams.set('interest', slug);
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
export function FeedView({ hasFollowing, onboarding = null, interest = null, interests = [], showComposer = true }: FeedViewProps) {
  const contextLocked = interest !== null;
  const [selectedInterestSlug, setSelectedInterestSlug] = useState(
    () => interest?.slug ?? interestFromLocation(),
  );
  const interestSlug = interest?.slug ?? (selectedInterestSlug === '' ? null : selectedInterestSlug);
  const selectedInterest = interest ?? interests.find((item) => item.slug === selectedInterestSlug) ?? null;
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
      const response = await communityApi.feed(selectedScope, cursor, interestSlug);
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
  }, [interestSlug]);

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

  const selectInterest = (slug: string): void => {
    if (contextLocked || slug === selectedInterestSlug) return;

    requestSequenceRef.current += 1;
    setPosts([]);
    setNextCursor(null);
    setError('');
    setLoading(true);
    replaceInterestInLocation(slug);
    setSelectedInterestSlug(slug);
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

  const scopeTabClass = (active: boolean): string => `-mb-px border-b-2 px-0.5 py-2.5 text-sm transition-colors ${
    active ? 'border-primary font-semibold text-foreground' : 'border-transparent font-medium text-muted-foreground hover:text-foreground'
  } disabled:cursor-not-allowed disabled:opacity-50`;
  const chipClass = (active: boolean): string => `h-8 shrink-0 rounded-full border px-3 text-sm transition-colors ${
    active ? 'border-primary bg-primary font-medium text-primary-foreground' : 'border-border text-muted-foreground hover:text-foreground'
  }`;

  return (
    <div className="space-y-5">
      {onboarding && <OnboardingChecklist onboarding={onboarding} />}
      {showComposer && (
        <PostComposer
          contextInterest={selectedInterest}
          lockContext={contextLocked}
          onCreated={(post) => {
            if (interestSlug === null || post.context_interest?.slug === interestSlug) {
              setPosts((current) => [post, ...current]);
            }
          }}
        />
      )}
      <div className="space-y-3">
        <div className="flex gap-6 border-b" role="group" aria-label="Feed scope">
          {followingDisabled ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <span data-testid="following-disabled-tooltip-trigger" tabIndex={0} className="inline-flex">
                  <button
                    type="button"
                    aria-pressed={false}
                    aria-describedby="feed-scope-following"
                    className={scopeTabClass(false)}
                    disabled
                  >
                    Following
                  </button>
                </span>
              </TooltipTrigger>
              <TooltipContent role="tooltip" side="top">
                you aren't following anyone yet.
              </TooltipContent>
            </Tooltip>
          ) : (
            <button
              type="button"
              aria-pressed={scope === 'following'}
              aria-describedby="feed-scope-following"
              className={scopeTabClass(scope === 'following')}
              onClick={() => selectScope('following')}
            >
              Following
            </button>
          )}
          <button
            type="button"
            aria-pressed={scope === 'mixed'}
            aria-describedby="feed-scope-mixed"
            className={scopeTabClass(scope === 'mixed')}
            onClick={() => selectScope('mixed')}
          >
            Everyone
          </button>
          <span id="feed-scope-following" className="sr-only">Only the people and personas you follow.</span>
          <span id="feed-scope-mixed" className="sr-only">Public posts from everyone, plus the people and personas you follow.</span>
        </div>
        {!contextLocked && interests.length > 0 && (
          <div role="group" aria-label="Filter by Interest" className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
            <button type="button" aria-pressed={selectedInterestSlug === ''} className={chipClass(selectedInterestSlug === '')} onClick={() => selectInterest('')}>
              All
            </button>
            {interests.map((item) => (
              <button
                key={item.slug}
                type="button"
                aria-pressed={selectedInterestSlug === item.slug}
                className={chipClass(selectedInterestSlug === item.slug)}
                onClick={() => selectInterest(item.slug)}
              >
                {item.name}
              </button>
            ))}
          </div>
        )}
      </div>
      {error && <p className="text-sm text-destructive">{error}</p>}
      {loading ? (
        <p className="text-sm text-muted-foreground">Loading feed...</p>
      ) : posts.length === 0 ? (
        <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
          {scope === 'following' && !followingDisabled ? (
            <p>
              Nothing new from the people you follow.{' '}
              <button type="button" className="font-medium text-foreground underline underline-offset-4" onClick={() => selectScope('mixed')}>
                See posts from everyone
              </button>
            </p>
          ) : (
            <p>No posts yet. Share something above{moduleEnabled('profiles') ? <>, or <a className="font-medium text-foreground underline underline-offset-4" href="/users">find people to follow</a></> : null}.</p>
          )}
        </div>
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
