import { BookOpen, GitBranch, Images } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { InterestPicker } from '@/components/interest-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { readInitialData } from '@/initialData';
import { MediaFilters } from '@/media/MediaFilters';
import { MediaGrid } from '@/media/MediaGrid';
import type { MediaItem, MediaTypeFilter, PagedResponse } from '@/media/types';
import { buildListingQuery, useMediaListing } from '@/media/useMediaListing';
import { storiesApi } from '@/stories/api';
import type { StoryDiscoveryItem } from '@/stories/types';

type ExploreTab = 'media' | 'stories';

interface UseStoryListing {
  items: StoryDiscoveryItem[];
  loading: boolean;
  loadingMore: boolean;
  hasMore: boolean;
  error: string | null;
  loadMore: () => void;
}

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : err instanceof Error ? err.message : 'Request failed.';
}

function useStoryListing(interestIds: number[], initial?: PagedResponse<StoryDiscoveryItem>): UseStoryListing {
  const [items, setItems] = useState<StoryDiscoveryItem[]>(initial?.data ?? []);
  const [loading, setLoading] = useState(initial === undefined);
  const [loadingMore, setLoadingMore] = useState(false);
  const [hasMore, setHasMore] = useState(initial?.meta?.has_more ?? false);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const skipInitialLoadRef = useRef(initial !== undefined);
  const interestKey = interestIds.join(',');

  const loadPage = useCallback(
    async (target: number): Promise<void> => {
      if (target > 1) setLoadingMore(true);
      else setLoading(true);
      setError(null);

      try {
        const query = buildListingQuery({ type: 'all', interestIds }, target);
        const response = await storiesApi.explore(query);
        setItems((current) => (target > 1 ? [...current, ...(response.data ?? [])] : response.data ?? []));
        setHasMore(response.meta?.has_more ?? false);
        setPage(target);
      } catch (err) {
        setError(getErrorMessage(err));
      } finally {
        setLoading(false);
        setLoadingMore(false);
      }
    },
    // interestKey stands in for interestIds; the array is intentionally rebuilt by React state.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [interestKey],
  );

  useEffect(() => {
    if (skipInitialLoadRef.current) {
      skipInitialLoadRef.current = false;
      return;
    }

    void loadPage(1);
  }, [loadPage]);

  const loadMore = useCallback(() => {
    if (!loadingMore && hasMore) {
      void loadPage(page + 1);
    }
  }, [hasMore, loadPage, loadingMore, page]);

  return { items, loading, loadingMore, hasMore, error, loadMore };
}

function StoryGrid({ items }: { items: StoryDiscoveryItem[] }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {items.map((story) => (
        <Card key={story.id}>
          <CardHeader className="flex flex-row items-start justify-between gap-2">
            <CardTitle className="text-base">{story.title}</CardTitle>
            <span className="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">
              {story.type === 'cyoa' ? <GitBranch className="h-3 w-3" /> : <BookOpen className="h-3 w-3" />}
              {story.type === 'cyoa' ? 'Adventure' : 'Story'}
            </span>
          </CardHeader>
          <CardContent className="grid gap-2 text-sm">
            <p className="text-xs text-muted-foreground">
              by {[story.owner?.display_name, ...story.authors.filter((author) => !author.is_owner).map((author) => author.display_name)].filter(Boolean).join(', ') || 'Unknown author'}
            </p>
            {story.type === 'cyoa' && (
              <p className="text-xs text-muted-foreground">{story.node_count ?? 0} passages</p>
            )}
            {story.interests.length > 0 && (
              <p className="truncate text-xs text-muted-foreground">{story.interests.map((interest) => interest.name).join(', ')}</p>
            )}
            <a className="text-sm underline underline-offset-4" href={`/s/${story.ulid}`}>
              Read
            </a>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

/**
 * Cross-user exploration. Media discovery mirrors the owner's library filters;
 * story discovery lists published, approved, discoverable stories and shares the
 * same interest filter.
 */
function ExplorePage() {
  const initial = readInitialData<{ explore?: { media?: PagedResponse<MediaItem>; stories?: PagedResponse<StoryDiscoveryItem> } }>().explore;
  const [tab, setTab] = useState<ExploreTab>('media');
  const [typeFilter, setTypeFilter] = useState<MediaTypeFilter>('all');
  const [interestIds, setInterestIds] = useState<number[]>([]);
  const mediaListing = useMediaListing('/api/explore', { type: typeFilter, interestIds }, initial?.media);
  const storyListing = useStoryListing(interestIds, initial?.stories);
  const activeListing = tab === 'media' ? mediaListing : storyListing;

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
        <div className="mt-6 flex justify-center">
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
