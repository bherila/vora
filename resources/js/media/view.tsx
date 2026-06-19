import { useState } from 'react';
import { createRoot } from 'react-dom/client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { readInitialData } from '@/initialData';
import { MediaPlayer } from '@/media/MediaPlayer';
import { formatBytes, type MediaItem } from '@/media/types';

function getInitialMedia(): MediaItem | null {
  return readInitialData<{ mediaView?: MediaItem }>().mediaView ?? null;
}

function MediaViewPage() {
  const [item] = useState<MediaItem | null>(getInitialMedia);
  return (
    <div className="mx-auto max-w-3xl px-4 py-8">
      {!item ? (
        <p className="text-muted-foreground">This media is unavailable.</p>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>{item.title || item.original_filename}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3">
            <div className="overflow-hidden rounded-md bg-muted">
              <MediaPlayer item={item} className="max-h-[70vh] w-full object-contain" />
            </div>
            <p className="text-xs text-muted-foreground">
              {item.type} · {formatBytes(item.size_bytes)}
            </p>
            {item.interests.length > 0 && (
              <p className="text-sm text-muted-foreground">{item.interests.map((i) => i.name).join(', ')}</p>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

const mountEl = document.getElementById('media-view');
if (mountEl) {
  createRoot(mountEl).render(<MediaViewPage />);
}
