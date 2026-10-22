import type { MediaItem } from '@/media/types';

export const EVENT_REFRESH_LIMIT = 40;

/** About fifteen minutes maximum, with fewer requests while work continues. */
export function eventRefreshDelay(attempt: number): number {
  return attempt < 6 ? 5_000 : attempt < 16 ? 15_000 : 30_000;
}

export function mediaNeedsRefresh(item: MediaItem): boolean {
  return item.upload_status === 'pending' || item.under_review === true
    || item.upload_status === 'ready' && item.type === 'video' && item.video?.status === 'processing';
}

export function eventMediaStatus(item: MediaItem): string {
  if (item.upload_status === 'pending') return 'Uploading';
  if (item.upload_status !== 'ready') return 'Unavailable';
  if (item.under_review) return 'Awaiting review';
  if (item.type === 'video' && item.video?.status === 'processing') return 'Preparing video';
  return (item.type === 'photo' ? item.url : item.video?.master_url) ? 'Shared' : 'Unavailable';
}
