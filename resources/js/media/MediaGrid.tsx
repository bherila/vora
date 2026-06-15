import type { ReactNode } from 'react';

import { Card, CardContent } from '@/components/ui/card';
import type { MediaItem } from '@/media/types';

interface MediaGridProps {
  items: MediaItem[];
  /** Optional per-item action buttons (e.g. delete) rendered in the card footer. */
  renderActions?: (item: MediaItem) => ReactNode;
}

/**
 * Unified, thumbnail-first grid of photos and videos. Listings render the small
 * signed thumbnail/poster (never the full original or the HLS stream) so a page
 * of media stays cheap; the full item loads only on the single-media view.
 */
export function MediaGrid({ items, renderActions }: MediaGridProps) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {items.map((item) => (
        <MediaCard key={item.id} item={item} actions={renderActions?.(item)} />
      ))}
    </div>
  );
}

interface MediaCardProps {
  item: MediaItem;
  actions?: ReactNode;
}

function MediaCard({ item, actions }: MediaCardProps) {
  const label = item.title || item.original_filename;

  return (
    <Card className="overflow-hidden">
      <a href={`/m/${item.ulid}`} className="block" aria-label={`Open ${label}`}>
        <div className="relative aspect-video bg-muted">
          <MediaThumbnail item={item} />
          {item.type === 'video' && (
            <span
              className="absolute inset-0 flex items-center justify-center"
              aria-hidden="true"
            >
              <span className="flex h-10 w-10 items-center justify-center rounded-full bg-black/55 text-white">
                ▶
              </span>
            </span>
          )}
        </div>
      </a>
      <CardContent className="grid gap-2 p-3">
        <p className="truncate text-sm font-medium" title={label}>
          {label}
        </p>
        {item.interests.length > 0 && (
          <p className="truncate text-xs text-muted-foreground">
            {item.interests.map((i) => i.name).join(', ')}
          </p>
        )}
        {actions && <div className="flex gap-2">{actions}</div>}
      </CardContent>
    </Card>
  );
}

function MediaThumbnail({ item }: { item: MediaItem }) {
  const alt = item.title ?? item.original_filename;

  // Prefer the lightweight thumbnail/poster. Photos can fall back to the signed
  // original; videos without a poster show a neutral placeholder rather than
  // pulling the full source into a grid.
  const src = item.thumbnail_url ?? (item.type === 'photo' ? item.url : null);

  if (src) {
    return <img src={src} alt={alt} loading="lazy" className="h-full w-full object-cover" />;
  }

  return (
    <span className="flex h-full w-full items-center justify-center text-xs text-muted-foreground">
      {item.upload_status === 'ready' ? 'No preview' : 'Processing…'}
    </span>
  );
}
