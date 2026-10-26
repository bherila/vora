import type { EventMediaItem } from '@/events/types';

export interface GallerySection {
  key: string;
  label: string;
  /** "4:05 – 11:40 PM" style span of the section's times, when known. */
  span: string | null;
  items: EventMediaItem[];
}

function validZone(timeZone: string | undefined): string | undefined {
  if (!timeZone) return undefined;
  try {
    new Intl.DateTimeFormat('en-US', { timeZone });
    return timeZone;
  } catch {
    return undefined;
  }
}

/** Calendar day of `iso` in the Event's time zone (the browser's when unknown). */
function dayKey(iso: string, timeZone: string | undefined): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date(iso));
  const part = (type: string): string => parts.find((entry) => entry.type === type)?.value ?? '';
  return `${part('year')}-${part('month')}-${part('day')}`;
}

function itemZone(item: EventMediaItem, fallback: string | undefined): string | undefined {
  return validZone(item.event?.timezone) ?? validZone(fallback);
}

/**
 * Split an already-ordered page into consecutive day sections. Times read in
 * each item's Event time zone, so every guest sees the wedding's own clock.
 */
export function gallerySections(items: EventMediaItem[], fallbackTimeZone?: string): GallerySection[] {
  const sections: GallerySection[] = [];
  for (const item of items) {
    const at = item.gallery_at ?? null;
    const timeZone = itemZone(item, fallbackTimeZone);
    const key = at === null || Number.isNaN(Date.parse(at)) ? 'undated' : `${dayKey(at, timeZone)}|${timeZone ?? ''}`;
    const last = sections.at(-1);
    if (last && last.key === key) last.items.push(item);
    else sections.push({ key, label: '', span: null, items: [item] });
  }
  for (const section of sections) {
    const first = section.items[0];
    if (section.key === 'undated' || !first?.gallery_at) {
      section.label = 'Date unknown';
      continue;
    }
    const timeZone = itemZone(first, fallbackTimeZone);
    section.label = new Intl.DateTimeFormat(undefined, { timeZone, weekday: 'long', month: 'long', day: 'numeric' }).format(new Date(first.gallery_at));
    const times = section.items.map((item) => item.gallery_at).filter((value): value is string => !!value).map((value) => new Date(value));
    const earliest = new Date(Math.min(...times.map(Number)));
    const latest = new Date(Math.max(...times.map(Number)));
    const time = new Intl.DateTimeFormat(undefined, { timeZone, hour: 'numeric', minute: '2-digit' });
    section.span = earliest.getTime() === latest.getTime() ? time.format(earliest) : time.formatRange(earliest, latest);
  }
  return sections;
}

/** "Taken Saturday, October 24 at 4:32 PM" in the Event's time zone. */
export function takenLabel(item: EventMediaItem, fallbackTimeZone?: string): string | null {
  if (!item.taken_at || Number.isNaN(Date.parse(item.taken_at))) return null;
  const timeZone = itemZone(item, fallbackTimeZone);
  return `Taken ${new Intl.DateTimeFormat(undefined, { timeZone, dateStyle: 'full', timeStyle: 'short' }).format(new Date(item.taken_at))}`;
}
