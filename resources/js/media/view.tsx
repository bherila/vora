import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { FavoriteButton } from '@/components/favorite-button';
import { readInitialData } from '@/initialData';
import { MediaPlayer } from '@/media/MediaPlayer';
import { formatBytes, type MediaItem } from '@/media/types';

function getInitialMedia(): MediaItem | null {
  return readInitialData<{ mediaView?: MediaItem }>().mediaView ?? null;
}

function MediaViewPage() {
  const [item] = useState<MediaItem | null>(getInitialMedia);

  if (!item) {
    return <div className="mx-auto max-w-3xl px-4 py-8"><p className="text-muted-foreground">This media is unavailable.</p></div>;
  }

  return (
    // A wide container plus a viewport-sized stage so the media uses the space
    // available on large screens and portrait phones alike. The stage is a fixed
    // fraction of the viewport height; object-contain scales the photo/video up
    // or down to fill it while preserving the original aspect ratio.
    <div className="mx-auto flex w-full max-w-6xl flex-col gap-3 px-4 py-6">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="truncate text-xl font-semibold">{item.title || item.original_filename}</h1>
        <div className="flex items-center gap-3">
          {typeof item.favorite_count === 'number' && item.favorite_count > 0 && (
            <span className="text-sm text-muted-foreground">{item.favorite_count} {item.favorite_count === 1 ? 'save' : 'saves'}</span>
          )}
          {item.favorited !== undefined && <FavoriteButton type="media" id={item.id} initialFavorited={item.favorited} />}
        </div>
      </div>
      <div className="flex h-[78svh] items-center justify-center overflow-hidden rounded-md bg-muted">
        <MediaPlayer item={item} className="mx-auto h-full w-full object-contain" />
      </div>
      <p className="text-xs text-muted-foreground">
        {item.type} · {formatBytes(item.size_bytes)}
      </p>
      {item.interests.length > 0 && (
        <p className="text-sm text-muted-foreground">{item.interests.map((i) => i.name).join(', ')}</p>
      )}
      <Toaster position="top-right" richColors closeButton />
    </div>
  );
}

const mountEl = document.getElementById('media-view');
if (mountEl) {
  createRoot(mountEl).render(<MediaViewPage />);
}
