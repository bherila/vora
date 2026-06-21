import { useEffect, useState } from 'react';

import { ProtectedImage } from '@/components/protected-image';
import { HlsVideoPlayer } from '@/media/HlsVideoPlayer';
import type { MediaItem } from '@/media/types';

interface MediaPlayerProps {
  item: MediaItem;
  className?: string;
}

/**
 * Renders a photo or video. Videos play the transcoded adaptive HLS stream via
 * the authenticated proxy (hls.js / native HLS). Original video files are never
 * used as a playback fallback.
 */
export function MediaPlayer({ item, className }: MediaPlayerProps) {
  const [hlsFailed, setHlsFailed] = useState(false);

  // Reset the HLS failure state when the item (or its readiness) changes.
  useEffect(() => {
    setHlsFailed(false);
  }, [item.id, item.video?.master_url]);

  if (item.upload_status !== 'ready') {
    return <p className="text-sm text-muted-foreground">Upload in progress…</p>;
  }

  if (item.type === 'photo') {
    if (!item.url) {
      return <p className="text-sm text-muted-foreground">Unavailable.</p>;
    }
    return <ProtectedImage src={item.url} alt={item.title ?? item.original_filename} className={className} />;
  }

  // Video.
  const masterUrl = item.video?.master_url ?? null;

  if (masterUrl && !hlsFailed) {
    return <HlsVideoPlayer src={masterUrl} className={className} onError={() => setHlsFailed(true)} />;
  }

  if (item.video?.status === 'processing') {
    return <p className="text-sm text-muted-foreground">Video is processing…</p>;
  }

  return <p className="text-sm text-muted-foreground">Unavailable.</p>;
}
