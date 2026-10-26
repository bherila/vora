import { gallerySections, takenLabel } from '@/events/capture-time';
import type { EventMediaItem } from '@/events/types';

function item(id: number, galleryAt: string | null, takenAt: string | null = galleryAt, timezone = 'America/Denver'): EventMediaItem {
  return {
    id, ulid: `m${id}`, character_id: null, type: 'photo', purpose: 'event', title: `Photo ${id}`, mime_type: 'image/jpeg', size_bytes: 1,
    audience: 'everyone', discoverable: false, upload_status: 'ready', url: '/x', thumbnail_url: null, video: null, interests: [], character: null,
    created_at: null, event: { ulid: 'e', name: 'Reception', timezone }, uploader: { id: 1, display_name: 'Sam' }, owned: false,
    download_url: null, favorited: false, gallery_at: galleryAt, taken_at: takenAt,
  } as EventMediaItem;
}

describe('gallerySections', () => {
  it('groups by calendar day in the Event time zone, not UTC', () => {
    // 22:00 and 23:30 Denver on the 24th are already the 25th in UTC.
    const sections = gallerySections([
      item(1, '2026-10-24T22:00:00Z'),
      item(2, '2026-10-25T04:00:00Z'),
      item(3, '2026-10-25T05:30:00Z'),
      item(4, '2026-10-25T17:00:00Z'),
    ]);
    expect(sections.map((section) => section.items.map((entry) => entry.id))).toEqual([[1, 2, 3], [4]]);
    expect(sections[0]?.label).toMatch(/Saturday.*October 24/);
    expect(sections[1]?.label).toMatch(/Sunday.*October 25/);
    expect(sections[0]?.span).toMatch(/4:00.*11:30/);
    expect(sections[1]?.span).toMatch(/^11:00/);
  });

  it('keeps undated items together and falls back to a valid zone', () => {
    const sections = gallerySections([item(1, null), item(2, null), item(3, '2026-10-24T18:00:00Z', null, 'Not/AZone')], 'UTC');
    expect(sections.map((section) => section.label)).toEqual(['Date unknown', expect.stringMatching(/October 24/)]);
    expect(sections[0]?.span).toBeNull();
  });
});

describe('takenLabel', () => {
  it('names the capture time in the Event time zone, and nothing when unknown', () => {
    expect(takenLabel(item(1, '2026-10-24T22:32:00Z'))).toMatch(/^Taken Saturday, October 24, 2026.*4:32/);
    expect(takenLabel(item(2, '2026-10-24T22:32:00Z', null))).toBeNull();
  });
});
