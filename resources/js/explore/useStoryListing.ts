import { useCallback, useEffect, useRef, useState } from 'react';

import type { PagedResponse } from '@/media/types';
import { buildListingQuery } from '@/media/useMediaListing';
import { storiesApi } from '@/stories/api';
import type { StoryDiscoveryItem } from '@/stories/types';

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

export function useStoryListing(interestIds: number[], initial?: PagedResponse<StoryDiscoveryItem>): UseStoryListing {
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
