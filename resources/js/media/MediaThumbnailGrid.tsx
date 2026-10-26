import { Check, Play } from 'lucide-react';
import type { ReactNode } from 'react';

import { MediaThumbnail } from '@/media/MediaGrid';
import type { MediaItem } from '@/media/types';
import { safeInternalUrl } from '@/security/dom-url';

interface MediaThumbnailGridProps {
  items: MediaItem[];
  getHref: (item: MediaItem) => string | null;
  /** While selecting, tiles toggle selection instead of opening the item. */
  selecting?: boolean;
  selectedIds?: number[];
  onSelectionChange?: (ids: number[]) => void;
  canSelect?: (item: MediaItem) => boolean;
  /** Small corner badges drawn over each tile (status, favorites, versions). */
  renderOverlay?: (item: MediaItem) => ReactNode;
}

/**
 * Caption-free square gallery, edge to edge on phones like a camera roll.
 * Order runs left to right, row by row, matching the viewer's next/previous.
 */
export function MediaThumbnailGrid({
  items,
  getHref,
  selecting = false,
  selectedIds = [],
  onSelectionChange,
  canSelect = () => true,
  renderOverlay,
}: MediaThumbnailGridProps) {
  const selected = new Set(selectedIds);
  const toggle = (item: MediaItem, checked: boolean): void => {
    onSelectionChange?.(checked ? [...selectedIds, item.id] : selectedIds.filter((id) => id !== item.id));
  };

  return (
    <ul className="-mx-4 grid grid-cols-3 gap-0.5 sm:mx-0 sm:grid-cols-4 sm:gap-1 lg:grid-cols-6" data-layout="media-thumbnails">
      {items.map((item) => {
        const label = item.title || item.original_filename || 'Untitled media';
        const href = safeInternalUrl(getHref(item));
        const selectable = selecting && !!onSelectionChange && canSelect(item);
        const tile = <MediaThumbnail item={item} />;

        return (
          <li key={item.id} className="relative aspect-square overflow-hidden bg-muted">
            {selectable ? (
              <label className="block h-full w-full cursor-pointer">
                <input
                  type="checkbox"
                  className="peer sr-only"
                  aria-label={`Select ${label}`}
                  checked={selected.has(item.id)}
                  onChange={(event) => toggle(item, event.target.checked)}
                />
                {tile}
                <span aria-hidden="true" className="pointer-events-none absolute inset-0 peer-checked:bg-black/25 peer-checked:ring-4 peer-checked:ring-inset peer-checked:ring-primary peer-focus-visible:ring-4 peer-focus-visible:ring-inset peer-focus-visible:ring-ring" />
                <span aria-hidden="true" className="pointer-events-none absolute right-1.5 top-1.5 z-10 flex size-6 items-center justify-center rounded-full border-2 border-white bg-black/30 text-primary-foreground shadow peer-checked:border-primary peer-checked:bg-primary [&>svg]:opacity-0 peer-checked:[&>svg]:opacity-100">
                  <Check className="size-4" strokeWidth={3} />
                </span>
              </label>
            ) : href && !selecting ? (
              <a href={href} className="block h-full w-full focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-ring" aria-label={`Open ${label}`}>{tile}</a>
            ) : (
              <div className={`h-full w-full ${selecting ? 'opacity-40' : ''}`}>{tile}</div>
            )}
            {item.type === 'video' && (
              <span aria-hidden="true" className="pointer-events-none absolute inset-0 flex items-center justify-center">
                <span className="flex size-8 items-center justify-center rounded-full bg-black/55 text-white"><Play className="size-4 fill-current" /></span>
              </span>
            )}
            {renderOverlay?.(item)}
          </li>
        );
      })}
    </ul>
  );
}
