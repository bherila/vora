import { useEffect, useState } from 'react';

import { HlsVideoPlayer } from '@/media/HlsVideoPlayer';
import type { MediaItem } from '@/media/types';

interface MediaPlayerProps {
  item: MediaItem;
  className?: string;
}

/**
 * Renders a photo or video. Videos play the transcoded adaptive HLS stream via
 * the authenticated proxy (hls.js / native HLS); on playback error — or while
 * the transcode is still processing — it falls back to a signed URL for the
 * original source file, which any browser can play in a plain <video>.
 */
export function MediaPlayer({ item, className }: MediaPlayerProps) {
  const [hlsFailed, setHlsFailed] = useState(false);

  // Reset the fallback flag when the item (or its readiness) changes.
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
    return <img src={item.url} alt={item.title ?? item.original_filename} className={className} />;
  }

  // Video.
  const masterUrl = item.video?.master_url ?? null;

  if (masterUrl && !hlsFailed) {
    return <HlsVideoPlayer src={masterUrl} className={className} onError={() => setHlsFailed(true)} />;
  }

  if (item.url) {
    return <video src={item.url} controls playsInline className={className} />;
  }

  if (item.video?.status === 'processing') {
    return <p className="text-sm text-muted-foreground">Video is processing…</p>;
  }

  return <p className="text-sm text-muted-foreground">Unavailable.</p>;
}
