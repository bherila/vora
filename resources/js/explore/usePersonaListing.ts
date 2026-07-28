import { useCallback, useEffect, useRef, useState } from 'react';

import { fetchWrapper } from '@/fetchWrapper';
import type { PagedResponse } from '@/media/types';
import { buildListingQuery } from '@/media/useMediaListing';

import type { PersonaDiscoveryItem } from './PersonaGrid';

interface UsePersonaListing {
  items: PersonaDiscoveryItem[];
  loading: boolean;
  loadingMore: boolean;
  hasMore: boolean;
  error: string | null;
  loadMore: () => void;
}

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : err instanceof Error ? err.message : 'Request failed.';
}

/** Paged listing of discoverable personas for Explore, mirroring useStoryListing. */
export function usePersonaListing(interestIds: number[], initial?: PagedResponse<PersonaDiscoveryItem>): UsePersonaListing {
  const [items, setItems] = useState<PersonaDiscoveryItem[]>(initial?.data ?? []);
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
        const response = (await fetchWrapper.get(`/api/explore/personas?${query}`)) as PagedResponse<PersonaDiscoveryItem>;
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
