import { BookOpen, Images } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { InterestPicker } from '@/components/interest-picker';
import { Button } from '@/components/ui/button';
import { StoryGrid } from '@/explore/StoryGrid';
import { useStoryListing } from '@/explore/useStoryListing';
import { readInitialData } from '@/initialData';
import { MediaFilters } from '@/media/MediaFilters';
import { MediaGrid } from '@/media/MediaGrid';
import type { MediaItem, MediaTypeFilter, PagedResponse } from '@/media/types';
import { useMediaListing } from '@/media/useMediaListing';
import type { StoryDiscoveryItem } from '@/stories/types';

type ExploreTab = 'media' | 'stories';

interface ExploreInitial {
  media?: PagedResponse<MediaItem>;
  stories?: PagedResponse<StoryDiscoveryItem>;
  default_interest_ids?: number[];
}

/** Order-independent set equality for two id lists. */
function sameIds(a: number[], b: number[]): boolean {
  if (a.length !== b.length) return false;
  const set = new Set(a);
  return b.every((id) => set.has(id));
}

/**
 * Cross-user exploration. Media discovery mirrors the owner's library filters;
 * story discovery lists published, approved, discoverable stories and shares the
 * same interest filter. Explore opens pre-filtered to the viewer's own profile
 * interests (seeded server-side); the viewer can reset to those defaults or clear
 * the filter to browse everything as an explicit exception.
 */
function ExplorePage() {
  const initial = readInitialData<{ explore?: ExploreInitial }>().explore;
  const defaultInterestIds = useMemo(() => initial?.default_interest_ids ?? [], [initial]);
  const [tab, setTab] = useState<ExploreTab>('media');
  const [typeFilter, setTypeFilter] = useState<MediaTypeFilter>('all');
  const [interestIds, setInterestIds] = useState<number[]>(() => initial?.default_interest_ids ?? []);
  const mediaListing = useMediaListing('/api/explore', { type: typeFilter, interestIds }, initial?.media);
  const storyListing = useStoryListing(interestIds, initial?.stories);
  const activeListing = tab === 'media' ? mediaListing : storyListing;

  const hasDefaults = defaultInterestIds.length > 0;
  const showingDefaults = hasDefaults && sameIds(interestIds, defaultInterestIds);

  // Auto-load the next page as the sentinel scrolls into view; the Load more
  // button stays as a fallback. Re-subscribes when the active tab changes.
  const sentinelRef = useRef<HTMLDivElement | null>(null);
  const { hasMore, loadingMore, loadMore } = activeListing;
  useEffect(() => {
    const el = sentinelRef.current;
    if (!el || !hasMore) return;

    const observer = new IntersectionObserver((entries) => {
      if (entries[0]?.isIntersecting && !loadingMore) {
        loadMore();
      }
    }, { rootMargin: '300px' });

    observer.observe(el);
    return () => observer.disconnect();
  }, [hasMore, loadingMore, loadMore]);

  return (
    <div className="mx-auto max-w-5xl px-4 py-8">
      <div className="mb-6">
        <h1 className="text-2xl font-bold">Explore</h1>
        <p className="text-muted-foreground">Discover media and stories shared by the community.</p>
      </div>

      <div className="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Explore content type">
        <Button type="button" size="sm" variant={tab === 'media' ? 'default' : 'outline'} aria-pressed={tab === 'media'} onClick={() => setTab('media')}>
          <Images className="h-4 w-4" /> Media
        </Button>
        <Button type="button" size="sm" variant={tab === 'stories' ? 'default' : 'outline'} aria-pressed={tab === 'stories'} onClick={() => setTab('stories')}>
          <BookOpen className="h-4 w-4" /> Stories
        </Button>
      </div>

      {hasDefaults && (
        <div className="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
          <span>{showingDefaults ? 'Showing your interests.' : 'Showing a custom filter.'}</span>
          {!showingDefaults && (
            <button type="button" className="font-medium text-foreground underline underline-offset-4" onClick={() => setInterestIds(defaultInterestIds)}>
              Reset to my interests
            </button>
          )}
          {interestIds.length > 0 && (
            <button type="button" className="font-medium text-foreground underline underline-offset-4" onClick={() => setInterestIds([])}>
              Clear
            </button>
          )}
        </div>
      )}

      {tab === 'media' ? (
        <MediaFilters
          type={typeFilter}
          onTypeChange={setTypeFilter}
          interestIds={interestIds}
          onInterestIdsChange={setInterestIds}
          disabled={mediaListing.loading}
        />
      ) : (
        <div className="mb-6 grid gap-1">
          <span className="text-sm text-muted-foreground">Filter by interest</span>
          <InterestPicker value={interestIds} onChange={setInterestIds} disabled={storyListing.loading} />
        </div>
      )}

      {activeListing.loading ? (
        <p className="text-muted-foreground">Loading...</p>
      ) : activeListing.error ? (
        <p className="text-destructive">{activeListing.error}</p>
      ) : activeListing.items.length === 0 ? (
        <p className="text-muted-foreground">Nothing to explore yet. Check back soon.</p>
      ) : tab === 'media' ? (
        <MediaGrid items={mediaListing.items} />
      ) : (
        <StoryGrid items={storyListing.items} />
      )}
      {activeListing.hasMore && (
        <div ref={sentinelRef} className="mt-6 flex justify-center">
          <Button type="button" variant="outline" disabled={activeListing.loadingMore} onClick={activeListing.loadMore}>
            {activeListing.loadingMore ? 'Loading...' : 'Load more'}
          </Button>
        </div>
      )}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('explore');
if (mountEl) {
  createRoot(mountEl).render(<ExplorePage />);
}
