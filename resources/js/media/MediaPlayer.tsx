import { useMemo } from 'react';

import type { MediaItem } from '@/media/types';

interface MediaPlayerProps {
  item: MediaItem;
  className?: string;
}

function canPlayNativeHls(): boolean {
  if (typeof document === 'undefined') {
    return false;
  }
  const el = document.createElement('video');
  return el.canPlayType('application/vnd.apple.mpegurl') !== '';
}

/**
 * Renders a photo or video. For video it prefers the transcoded HLS stream
 * where the browser plays HLS natively (Safari); otherwise it falls back to a
 * signed URL for the original source file, which every browser can play via a
 * plain <video> element. (A future enhancement could load hls.js for adaptive
 * playback in other browsers.)
 */
export function MediaPlayer({ item, className }: MediaPlayerProps) {
  const videoSrc = useMemo(() => {
    if (item.type !== 'video') {
      return null;
    }
    const hls = item.video?.playback_url ?? null;
    if (hls && canPlayNativeHls()) {
      return hls;
    }
    return item.url;
  }, [item]);

  if (item.upload_status !== 'ready') {
    return <p className="text-sm text-muted-foreground">Upload in progress…</p>;
  }

  if (item.type === 'photo') {
    if (!item.url) {
      return <p className="text-sm text-muted-foreground">Unavailable.</p>;
    }
    return <img src={item.url} alt={item.title ?? item.original_filename} className={className} />;
  }

  if (item.video?.status === 'processing' && !videoSrc) {
    return <p className="text-sm text-muted-foreground">Video is processing…</p>;
  }

  if (!videoSrc) {
    return <p className="text-sm text-muted-foreground">Unavailable.</p>;
  }

  return <video src={videoSrc} controls playsInline className={className} />;
}
