import { useCallback, useEffect, useRef, useState } from 'react';

import { fetchWrapper } from '@/fetchWrapper';
import type { MediaItem, MediaTypeFilter, PagedResponse } from '@/media/types';

interface MediaListingFilters {
  type: MediaTypeFilter;
  interestIds: number[];
}

/**
 * Build the query string shared by every media listing endpoint: an optional
 * type constraint and repeated interest_ids, plus the page. Keeping this in one
 * place means the owner library and exploration send identical params.
 */
export function buildListingQuery(filters: MediaListingFilters, page: number): string {
  const params = new URLSearchParams();
  params.set('page', String(page));
  if (filters.type !== 'all') {
    params.set('type', filters.type);
  }
  for (const id of filters.interestIds) {
    params.append('interest_ids[]', String(id));
  }
  return params.toString();
}

interface UseMediaListing {
  items: MediaItem[];
  loading: boolean;
  loadingMore: boolean;
  hasMore: boolean;
  error: string | null;
  loadMore: () => void;
  /** Reload from page 1 (e.g. after an upload or filter change). */
  reload: () => void;
  /** Drop an item from local state without refetching (e.g. after delete). */
  removeLocal: (id: number) => void;
}

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : err instanceof Error ? err.message : 'Request failed.';
}

/**
 * Loads a paginated, filterable media listing from `endpoint` (e.g. `/api/media`
 * or `/api/explore`). The two surfaces differ only by endpoint and which actions
 * they render — the fetching, filtering, and pagination are identical and live
 * here so they cannot drift.
 */
export function useMediaListing(endpoint: string, filters: MediaListingFilters, initial?: PagedResponse<MediaItem>): UseMediaListing {
  const [items, setItems] = useState<MediaItem[]>(initial?.data ?? []);
  const [loading, setLoading] = useState(initial === undefined);
  const [loadingMore, setLoadingMore] = useState(false);
  const [hasMore, setHasMore] = useState(initial?.meta?.has_more ?? false);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const skipInitialLoadRef = useRef(initial !== undefined);

  const { type, interestIds } = filters;
  // Serialize the interest list so the effect re-runs on content change, not identity.
  const interestKey = interestIds.join(',');

  const loadPage = useCallback(
    async (target: number): Promise<void> => {
      if (target > 1) {
        setLoadingMore(true);
      } else {
        setLoading(true);
      }
      setError(null);
      try {
        const query = buildListingQuery({ type, interestIds }, target);
        const response = (await fetchWrapper.get(`${endpoint}?${query}`)) as PagedResponse<MediaItem>;
        const next = response.data ?? [];
        setItems((current) => (target > 1 ? [...current, ...next] : next));
        setHasMore(response.meta?.has_more ?? false);
        setPage(target);
      } catch (err) {
        setError(getErrorMessage(err));
      } finally {
        setLoading(false);
        setLoadingMore(false);
      }
    },
    // interestKey stands in for interestIds; endpoint/type are primitives.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [endpoint, type, interestKey],
  );

  // Reload from page 1 whenever the endpoint or filters change.
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
  }, [loadPage, loadingMore, hasMore, page]);

  const reload = useCallback(() => {
    void loadPage(1);
  }, [loadPage]);

  const removeLocal = useCallback((id: number) => {
    setItems((current) => current.filter((m) => m.id !== id));
  }, []);

  return { items, loading, loadingMore, hasMore, error, loadMore, reload, removeLocal };
}
