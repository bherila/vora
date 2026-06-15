import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { Button } from '@/components/ui/button';
import { MediaFilters } from '@/media/MediaFilters';
import { MediaGrid } from '@/media/MediaGrid';
import type { MediaTypeFilter } from '@/media/types';
import { useMediaListing } from '@/media/useMediaListing';

/**
 * Cross-user exploration. Reuses the exact filtering, grid, and pagination of
 * the owner's library — only the endpoint differs (`/api/explore`, which returns
 * just the media the viewer is allowed to discover). No upload or delete here.
 */
function ExplorePage() {
  const [typeFilter, setTypeFilter] = useState<MediaTypeFilter>('all');
  const [interestIds, setInterestIds] = useState<number[]>([]);
  const listing = useMediaListing('/api/explore', { type: typeFilter, interestIds });

  return (
    <div className="mx-auto max-w-5xl px-4 py-8">
      <div className="mb-6">
        <h1 className="text-2xl font-bold">Explore</h1>
        <p className="text-muted-foreground">Discover photos and videos shared by the community.</p>
      </div>

      <MediaFilters
        type={typeFilter}
        onTypeChange={setTypeFilter}
        interestIds={interestIds}
        onInterestIdsChange={setInterestIds}
        disabled={listing.loading}
      />

      {listing.loading ? (
        <p className="text-muted-foreground">Loading…</p>
      ) : listing.error ? (
        <p className="text-destructive">{listing.error}</p>
      ) : listing.items.length === 0 ? (
        <p className="text-muted-foreground">Nothing to explore yet — check back soon.</p>
      ) : (
        <MediaGrid items={listing.items} />
      )}
      {listing.hasMore && (
        <div className="mt-6 flex justify-center">
          <Button type="button" variant="outline" disabled={listing.loadingMore} onClick={listing.loadMore}>
            {listing.loadingMore ? 'Loading…' : 'Load more'}
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
