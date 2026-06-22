import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { Avatar } from '@/components/avatar';
import { FavoriteButton } from '@/components/favorite-button';
import { ReportButton } from '@/components/report-button';
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

  const owner = item.owner ?? null;

  return (
    // A wide container plus a viewport-sized stage so the media uses the space
    // available on large screens and portrait phones alike. The stage is a fixed
    // fraction of the viewport height; object-contain scales the photo/video up
    // or down to fill it while preserving the original aspect ratio.
    <div className="mx-auto flex w-full max-w-6xl flex-col gap-3 px-4 py-6">
      <a href="/explore" className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Back to Explore
      </a>

      {/* Frame the item inside the uploader's profile: their identity heads the
          page and links through to the full profile. */}
      {owner && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3">
          <a href={owner.href} className="flex min-w-0 items-center gap-3">
            <Avatar name={owner.display_name} src={owner.avatar_url} sizeClassName="h-10 w-10" />
            <span className="min-w-0">
              <span className="block text-xs text-muted-foreground">{owner.is_self ? 'Your media' : 'Uploaded by'}</span>
              <span className="block truncate font-medium">{owner.display_name}</span>
            </span>
          </a>
          <a href={owner.href} className="text-sm underline underline-offset-4">{owner.is_self ? 'Go to your profile' : 'View profile'}</a>
        </div>
      )}

      {owner?.is_self && item.under_review && (
        <p className="rounded-md border border-amber-300/60 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-700/50 dark:bg-amber-950/40 dark:text-amber-200">
          Only you can see this — it’s awaiting review before others can.
        </p>
      )}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="truncate text-xl font-semibold">{item.title || item.original_filename}</h1>
        <div className="flex items-center gap-3">
          {typeof item.favorite_count === 'number' && item.favorite_count > 0 && (
            <span className="text-sm text-muted-foreground">{item.favorite_count} {item.favorite_count === 1 ? 'save' : 'saves'}</span>
          )}
          {item.favorited !== undefined && <FavoriteButton type="media" id={item.id} initialFavorited={item.favorited} />}
          {item.can_report && <ReportButton type="media" id={item.id} />}
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
