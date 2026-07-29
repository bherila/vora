import type { ReactNode } from 'react';

import { ProtectedImage } from '@/components/protected-image';
import { Card, CardContent } from '@/components/ui/card';
import type { MediaItem } from '@/media/types';

interface MediaGridProps {
  items: MediaItem[];
  /** Optional per-item action buttons (e.g. delete) rendered in the card footer. */
  renderActions?: (item: MediaItem) => ReactNode;
  getHref?: (item: MediaItem) => string | null;
  selectedIds?: number[];
  onSelectionChange?: (ids: number[]) => void;
}

/**
 * Unified, thumbnail-first grid of photos and videos. Listings render the small
 * signed thumbnail/poster (never the full original or the HLS stream) so a page
 * of media stays cheap; the full item loads only on the single-media view.
 */
export function MediaGrid({ items, renderActions, getHref = (item) => `/m/${item.ulid}`, selectedIds = [], onSelectionChange }: MediaGridProps) {
  const selected = new Set(selectedIds);
  const toggle = (item: MediaItem, checked: boolean): void => {
    if (checked) {
      onSelectionChange?.([...selectedIds, item.id]);
    } else {
      onSelectionChange?.(selectedIds.filter((id) => id !== item.id));
    }
  };

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5" data-layout="media-grid">
      {items.map((item) => (
        <MediaCard
          key={item.id}
          item={item}
          actions={renderActions?.(item)}
          href={getHref(item)}
          selected={selected.has(item.id)}
          onSelectedChange={onSelectionChange ? (checked) => toggle(item, checked) : undefined}
        />
      ))}
    </div>
  );
}

interface MediaCardProps {
  item: MediaItem;
  actions?: ReactNode;
  href: string | null;
  selected: boolean;
  onSelectedChange?: ((checked: boolean) => void) | undefined;
}

function MediaCard({ item, actions, href, selected, onSelectedChange }: MediaCardProps) {
  const label = item.title || item.original_filename || 'Untitled media';
  const preview = (
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
  );

  return (
    <Card className="overflow-hidden">
      <div className="relative">
        {onSelectedChange && (
          <label className="absolute left-2 top-2 z-10 flex h-8 w-8 items-center justify-center rounded border border-background/80 bg-background/90 shadow-sm">
            <span className="sr-only">Select {label}</span>
            <input
              type="checkbox"
              checked={selected}
              onChange={(event) => onSelectedChange(event.target.checked)}
              className="h-4 w-4"
            />
          </label>
        )}
        {href ? (
          <a href={href} className="block" aria-label={`Open ${label}`}>
            {preview}
          </a>
        ) : preview}
      </div>
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
  const alt = item.title || item.original_filename || 'Untitled media';

  // Prefer the lightweight thumbnail/poster. Photos can fall back to the signed
  // original; videos without a poster show a neutral placeholder rather than
  // pulling the full source into a grid.
  const src = item.thumbnail_url ?? (item.type === 'photo' ? item.url : null);

  if (src) {
    return <ProtectedImage src={src} alt={alt} loading="lazy" className="h-full w-full object-cover" />;
  }

  return (
    <span className="flex h-full w-full items-center justify-center text-xs text-muted-foreground">
      {item.upload_status === 'ready' ? 'No preview' : 'Processing…'}
    </span>
  );
}
