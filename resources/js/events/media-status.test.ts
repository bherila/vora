import { eventMediaStatus, eventRefreshDelay, mediaNeedsRefresh } from '@/events/media-status';
import type { MediaItem } from '@/media/types';

const photo: MediaItem = { id: 1, ulid: 'photo', character_id: null, type: 'photo', purpose: 'event', title: null, mime_type: 'image/jpeg', size_bytes: 5, audience: 'specific', discoverable: false, upload_status: 'ready', url: '/photo', thumbnail_url: null, video: null, interests: [], character: null, created_at: null };

describe('Event media status', () => {
  it('distinguishes upload, review, playback preparation, shared and unavailable states', () => {
    expect(eventMediaStatus({ ...photo, upload_status: 'pending' })).toBe('Uploading');
    expect(eventMediaStatus({ ...photo, under_review: true })).toBe('Awaiting review');
    expect(eventMediaStatus({ ...photo, type: 'video', video: { status: 'processing', master_url: null } })).toBe('Preparing video');
    expect(eventMediaStatus(photo)).toBe('Shared');
    expect(eventMediaStatus({ ...photo, upload_status: 'failed' })).toBe('Unavailable');
    expect(eventMediaStatus({ ...photo, url: null })).toBe('Unavailable');
  });

  it('refreshes transitional states, stops settled or failed media, and backs off', () => {
    expect(mediaNeedsRefresh({ ...photo, upload_status: 'pending' })).toBe(true);
    expect(mediaNeedsRefresh({ ...photo, under_review: true })).toBe(true);
    expect(mediaNeedsRefresh({ ...photo, type: 'video', video: { status: 'processing', master_url: null } })).toBe(true);
    expect(mediaNeedsRefresh(photo)).toBe(false);
    expect(mediaNeedsRefresh({ ...photo, upload_status: 'failed' })).toBe(false);
    expect([eventRefreshDelay(0), eventRefreshDelay(6), eventRefreshDelay(16)]).toEqual([5_000, 15_000, 30_000]);
  });
});
