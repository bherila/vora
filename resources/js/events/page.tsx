import { type MouseEvent, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';

import { FavoriteButton } from '@/components/favorite-button';
import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { CuratorEditor, EventEditor, EventMediaDialog } from '@/events/EventDialogs';
import { type EventAttendee, eventError, type EventMediaItem, type EventProfile, type EventSettings } from '@/events/types';
import { fetchWrapper } from '@/fetchWrapper';
import { MediaGrid } from '@/media/MediaGrid';
import { MediaUploadDialog } from '@/media/MediaUploadDialog';
import type { PageMeta } from '@/media/types';

function pageUrl(params: URLSearchParams, changes: Record<string, string | null>, pathname = window.location.pathname): string {
  const next = new URLSearchParams(params);
  if ('item' in changes) next.delete('media');
  for (const [key, value] of Object.entries(changes)) {
    if (value) next.set(key, value);
    else next.delete(key);
  }
  return `${pathname}${next.size ? `?${next.toString()}` : ''}`;
}

function eventDate(event: EventProfile): string | null {
  if (!event.starts_at) return null;
  try {
    return `${new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: event.timezone }).format(new Date(event.starts_at))} (${event.timezone})`;
  } catch { return event.starts_at; }
}

export function EventsPage() {
  const [location, setLocation] = useState(() => window.location.pathname + window.location.search);
  const [events, setEvents] = useState<EventProfile[]>([]);
  const [attendees, setAttendees] = useState<EventAttendee[]>([]);
  const [settings, setSettings] = useState<EventSettings | null>(null);
  const [items, setItems] = useState<EventMediaItem[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [initialLoading, setInitialLoading] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [mediaError, setMediaError] = useState('');
  const [detailError, setDetailError] = useState('');
  const [detailLoading, setDetailLoading] = useState(false);
  const [activeItem, setActiveItem] = useState<EventMediaItem | null>(null);
  const [revision, setRevision] = useState(0);
  const [editor, setEditor] = useState<EventProfile | null>();
  const [curatorsOpen, setCuratorsOpen] = useState(false);
  const parsed = new URL(location, window.location.origin);
  const params = parsed.searchParams;
  const section = params.get('section') ?? 'all';
  const attendeePath = parsed.pathname.match(/^\/(?:events\/)?attendees\/(\d+)\/?$/);
  const attendeeId = attendeePath?.[1] ?? params.get('attendee_id') ?? '';
  const attendeeIndex = /^\/(?:events\/)?attendees\/?$/.test(parsed.pathname);
  const eventUlid = params.get('event_ulid') ?? '';
  const favoritesOf = params.get('favorites_of') ?? '';
  const featured = params.get('featured') === '1';
  const itemUlid = params.get('item') ?? params.get('media') ?? '';
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
  const selectedEvent = events.find((event) => event.ulid === eventUlid);
  const selectedAttendee = attendees.find((attendee) => String(attendee.id) === attendeeId);
  const isEventIndex = section === 'events' && !attendeeIndex && !attendeeId;
  const showMedia = !isEventIndex && !attendeeIndex;
  const refresh = (): void => setRevision((value) => value + 1);
  const navigate = (url: string): void => {
    window.history.pushState({}, '', url);
    setLocation(window.location.pathname + window.location.search);
  };
  const closeItem = (): void => {
    navigate(pageUrl(params, { item: null }));
    if (activeItem) refresh();
  };
  const followInternalLink = (event: MouseEvent<HTMLElement>): void => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const anchor = (event.target as HTMLElement).closest('a');
    if (!anchor || anchor.origin !== window.location.origin || !anchor.pathname.startsWith('/events') && !anchor.pathname.startsWith('/attendees')) return;
    event.preventDefault();
    navigate(anchor.pathname + anchor.search);
  };

  useEffect(() => {
    const popstate = (): void => setLocation(window.location.pathname + window.location.search);
    window.addEventListener('popstate', popstate);
    return () => window.removeEventListener('popstate', popstate);
  }, []);

  useEffect(() => {
    let active = true;
    setError('');
    Promise.all([
      fetchWrapper.get('/api/events') as Promise<{ data: EventProfile[] }>,
      fetchWrapper.get('/api/events/attendees') as Promise<{ data: EventAttendee[] }>,
      fetchWrapper.get('/api/events/settings') as Promise<{ data: EventSettings }>,
    ]).then(([eventResponse, attendeeResponse, settingsResponse]) => {
      if (!active) return;
      setEvents(eventResponse.data);
      setAttendees(attendeeResponse.data);
      setSettings(settingsResponse.data);
    }).catch((error: unknown) => { if (active) setError(eventError(error)); })
      .finally(() => { if (active) setInitialLoading(false); });
    return () => { active = false; };
  }, [revision]);

  const viewerId = settings?.viewer_id;
  useEffect(() => {
    if (!showMedia || !viewerId) return;
    let active = true;
    setLoading(true);
    setMediaError('');
    setItems([]);
    setMeta(null);
    const search = new URLSearchParams({ page: String(page) });
    if (eventUlid) search.set('event_ulid', eventUlid);
    if (section === 'mine') search.set('attendee_id', String(viewerId));
    else if (attendeeId) search.set('attendee_id', attendeeId);
    if (favoritesOf) search.set('favorites_of', favoritesOf);
    if (featured) search.set('featured', '1');
    fetchWrapper.get(`/api/events/media?${search.toString()}`).then((response) => {
      if (!active) return;
      const result = response as { data: EventMediaItem[]; meta: PageMeta };
      setItems(result.data);
      setMeta(result.meta);
    }).catch((error: unknown) => { if (active) setMediaError(eventError(error)); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [showMedia, viewerId, page, eventUlid, section, attendeeId, favoritesOf, featured, revision]);

  useEffect(() => {
    let active = true;
    setActiveItem(null);
    setDetailError('');
    if (!itemUlid) { setDetailLoading(false); return; }
    setDetailLoading(true);
    fetchWrapper.get(`/api/events/media/${encodeURIComponent(itemUlid)}`).then((response) => {
      if (active) setActiveItem((response as { data: EventMediaItem }).data);
    }).catch((error: unknown) => { if (active) setDetailError(eventError(error)); })
      .finally(() => { if (active) setDetailLoading(false); });
    return () => { active = false; };
  }, [itemUlid]);

  const allMediaUrl = pageUrl(params, { section: null, attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/events');
  const eventsUrl = pageUrl(params, { section: 'events', attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/events');
  const attendeesUrl = pageUrl(params, { section: null, attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/attendees');
  const featuredUrl = pageUrl(params, { section: null, attendee_id: null, favorites_of: null, featured: '1', page: null, item: null }, '/events');
  const mineUrl = pageUrl(params, { section: 'mine', attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/events');

  return <main className={`mx-auto w-full ${BROWSING_PAGE_WIDTH} space-y-6 px-4 py-8`} onClick={followInternalLink}>
    <Toaster />
    <header className="flex flex-wrap items-start justify-between gap-4">
      <div><h1 className="text-2xl font-bold">Events</h1><p className="mt-1 text-sm text-muted-foreground">Share photos and videos with event attendees.</p></div>
      {settings?.is_admin && <div className="flex flex-wrap gap-2"><Button variant="outline" onClick={() => setCuratorsOpen(true)}>Manage featured curators</Button><Button onClick={() => setEditor(null)}>Create event</Button></div>}
    </header>
    <nav aria-label="Event navigation" className="flex flex-wrap gap-2">
      {[{ label: 'All media', href: allMediaUrl, current: showMedia && !featured && section !== 'mine' && !attendeeId }, { label: 'Events', href: eventsUrl, current: isEventIndex }, { label: 'Attendees', href: attendeesUrl, current: attendeeIndex || !!attendeeId }, { label: 'Featured favorites', href: featuredUrl, current: featured }, { label: 'My uploads', href: mineUrl, current: section === 'mine' }].map(({ label, href, current }) => <a key={label} href={href} aria-current={current ? 'page' : undefined} className={`rounded-md px-3 py-2 text-sm ${current ? 'bg-primary text-primary-foreground' : 'bg-muted hover:bg-accent'}`}>{label}</a>)}
      <a href="/library" className="rounded-md bg-muted px-3 py-2 text-sm hover:bg-accent">My collection</a>
    </nav>
    {initialLoading ? <p role="status">Loading events…</p> : error ? <div role="alert" className="space-y-2 text-sm text-destructive"><p>{error}</p><Button variant="outline" onClick={refresh}>Retry</Button></div> : <>
      <div className="flex flex-wrap items-end gap-4">
        <div className="grid min-w-48 gap-1"><Label htmlFor="event-filter">Event</Label><select id="event-filter" className="rounded-md border bg-background p-2" value={eventUlid} onChange={(event) => navigate(pageUrl(params, { event_ulid: event.target.value, page: null, item: null }))}><option value="">All events</option>{events.map((event) => <option key={event.ulid} value={event.ulid}>{event.name}</option>)}</select></div>
        {showMedia && section !== 'mine' && <div className="grid min-w-48 gap-1"><Label htmlFor="attendee-filter">Uploaded by</Label><select id="attendee-filter" className="rounded-md border bg-background p-2" value={attendeeId} onChange={(event) => navigate(pageUrl(params, { attendee_id: event.target.value, section: null, page: null, item: null }, '/events'))}><option value="">All attendees</option>{attendees.map((attendee) => <option key={attendee.id} value={attendee.id}>{attendee.display_name}</option>)}</select></div>}
        {showMedia && <div className="grid min-w-48 gap-1"><Label htmlFor="favorites-filter">Favorites by</Label><select id="favorites-filter" className="rounded-md border bg-background p-2" value={favoritesOf} onChange={(event) => navigate(pageUrl(params, { favorites_of: event.target.value, page: null, item: null }))}><option value="">Any / unfiltered</option>{attendees.map((attendee) => <option key={attendee.id} value={attendee.id}>{attendee.display_name}</option>)}</select></div>}
        {selectedEvent ? <MediaUploadDialog key={selectedEvent.ulid} eventUlid={selectedEvent.ulid} characters={[]} lastInterestIds={[]} onUploaded={refresh} triggerLabel={`Upload to ${selectedEvent.name}`} /> : <p className="text-sm text-muted-foreground">Choose an event above to upload photos or videos.</p>}
      </div>
      {selectedEvent && <section className="space-y-2 rounded border p-4">
        <div className="flex flex-wrap items-center justify-between gap-2"><h2 className="text-lg font-semibold">{selectedEvent.name}</h2>{selectedEvent.can_manage && <Button size="sm" variant="outline" onClick={() => setEditor(selectedEvent)}>Edit event</Button>}</div>
        {selectedEvent.description && <p className="whitespace-pre-wrap text-sm">{selectedEvent.description}</p>}
        {eventDate(selectedEvent) && <p className="text-sm text-muted-foreground">{eventDate(selectedEvent)}</p>}
        <Badge variant="outline">{selectedEvent.visibility === 'everyone' ? 'All attendees' : 'Selected attendees only'}</Badge>
        <p className="text-xs text-muted-foreground">Uploads inherit this event’s access and appear to others after review.</p>
      </section>}
      {eventUlid && !selectedEvent && <p role="alert" className="text-sm text-destructive">This event is unavailable or you do not have access.</p>}
      {isEventIndex ? <section aria-label="Available events" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {events.length === 0 && <p>No events are available to you yet.</p>}
        {events.map((event) => <Card key={event.ulid}><CardContent className="grid gap-3 p-4"><h2 className="font-semibold"><a href={pageUrl(params, { event_ulid: event.ulid, section: null, page: null, item: null }, '/events')} className="underline underline-offset-4">{event.name}</a></h2>{event.description && <p className="text-sm">{event.description}</p>}{eventDate(event) && <p className="text-xs text-muted-foreground">{eventDate(event)}</p>}<p className="text-xs">{event.visibility === 'everyone' ? 'All attendees' : 'Selected attendees only'}</p>{event.can_manage && <Button size="sm" variant="outline" onClick={() => setEditor(event)}>Edit {event.name}</Button>}</CardContent></Card>)}
      </section> : attendeeIndex ? <section aria-label="Attendee galleries" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {attendees.length === 0 && <p>No attendees are available.</p>}
        {attendees.map((attendee) => <Card key={attendee.id}><CardContent className="grid gap-3 p-4"><h2 className="font-semibold">{attendee.display_name}</h2><a className="text-sm underline" href={pageUrl(params, { section: null, favorites_of: null, page: null, item: null }, `/attendees/${attendee.id}`)}>View uploads by {attendee.display_name}</a><a className="text-sm underline" href={pageUrl(params, { section: null, favorites_of: String(attendee.id), page: null, item: null }, '/events')}>View favorites by {attendee.display_name}</a></CardContent></Card>)}
      </section> : <section className="space-y-4" aria-label="Event media">
        <h2 className="text-lg font-semibold">{featured ? 'Featured favorites' : section === 'mine' ? 'My uploads' : selectedAttendee ? `Uploads by ${selectedAttendee.display_name}` : 'All media'}</h2>
        {featured && <p className="text-sm text-muted-foreground">Favorites selected by {settings?.featured_curators.map((curator) => curator.display_name).join(', ') || 'the featured curators'}. Only media you can access appears here.</p>}
        {mediaError ? <p role="alert" className="text-sm text-destructive">{mediaError}</p> : loading ? <p role="status">Loading media…</p> : items.length === 0 ? <p className="text-sm text-muted-foreground">No media matches these filters.</p> : <MediaGrid items={items} getHref={(item) => pageUrl(params, { item: item.ulid })} renderActions={(media) => {
          const item = items.find((entry) => entry.id === media.id);
          return item && <div className="flex flex-wrap items-center gap-2"><FavoriteButton key={`${item.id}:${item.favorited}`} type="media" id={item.id} initialFavorited={item.favorited} /><span className="text-xs text-muted-foreground">{item.uploader.display_name}{item.event ? ` · ${item.event.name}` : ''}{item.under_review ? ' · Awaiting review' : ''}</span></div>;
        }} />}
        {meta && meta.last_page > 1 && <nav className="flex items-center justify-between gap-3" aria-label="Media pages"><Button variant="outline" disabled={page <= 1 || loading} onClick={() => navigate(pageUrl(params, { page: String(page - 1), item: null }))}>Previous</Button><span className="text-sm">Page {meta.current_page} of {meta.last_page}</span><Button variant="outline" disabled={!meta.has_more || loading} onClick={() => navigate(pageUrl(params, { page: String(page + 1), item: null }))}>Next</Button></nav>}
      </section>}
    </>}
    {detailLoading && <p role="status">Loading media details…</p>}
    {detailError && <div role="alert" className="flex items-center gap-3 text-sm text-destructive"><p>{detailError}</p><Button variant="outline" onClick={closeItem}>Dismiss</Button></div>}
    {activeItem && <EventMediaDialog key={activeItem.ulid} item={activeItem} onClose={closeItem} onDeleted={refresh} />}
    {editor !== undefined && <EventEditor event={editor} attendees={attendees} onClose={() => setEditor(undefined)} onSaved={refresh} />}
    {curatorsOpen && settings && <CuratorEditor settings={settings} attendees={attendees} onClose={() => setCuratorsOpen(false)} onSaved={refresh} />}
  </main>;
}

const element = document.getElementById('events');
if (element) createRoot(element).render(<EventsPage />);
