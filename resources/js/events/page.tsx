import { type MouseEvent, type PointerEvent, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { toast, Toaster } from 'sonner';

import { FavoriteButton } from '@/components/favorite-button';
import { BROWSING_PAGE_WIDTH } from '@/components/page-width';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CuratorEditor, EventEditor, EventMediaDialog } from '@/events/EventDialogs';
import { EVENT_REFRESH_LIMIT, eventMediaStatus, eventRefreshDelay, mediaNeedsRefresh } from '@/events/media-status';
import { type EventAttendee, eventError, type EventMediaItem, type EventProfile, type EventSettings } from '@/events/types';
import { fetchWrapper } from '@/fetchWrapper';
import { moduleEnabled } from '@/instance';
import { BulkTransferActions } from '@/media/BulkTransferActions';
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
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
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
  const [filtersOpen, setFiltersOpen] = useState<boolean | null>(null);
  const [joiningLinkFallback, setJoiningLinkFallback] = useState('');
  const [listRevision, setListRevision] = useState(0);
  const [listRefreshPaused, setListRefreshPaused] = useState(false);
  const [detailRefreshPaused, setDetailRefreshPaused] = useState(false);
  const [detailRevision, setDetailRevision] = useState(0);
  const galleryScroll = useRef(0);
  const galleryPointerStart = useRef<{ anchor: HTMLAnchorElement; scroll: number } | null>(null);
  const itemRef = useRef('');
  const openedHere = useRef(false);
  const pendingEdge = useRef<'first' | 'last' | null>(null);
  const previousListing = useRef('');
  const pendingScrollRestore = useRef<number | null>(null);
  const parsed = new URL(location, window.location.origin);
  const params = parsed.searchParams;
  const favoritesEnabled = moduleEnabled('favorites');
  const section = params.get('section') ?? 'all';
  const attendeePath = parsed.pathname.match(/^\/(?:events\/)?attendees\/(\d+)\/?$/);
  const attendeeId = attendeePath?.[1] ?? params.get('attendee_id') ?? '';
  const attendeeIndex = /^\/(?:events\/)?attendees\/?$/.test(parsed.pathname);
  const eventUlid = params.get('event_ulid') ?? '';
  const favoritesOf = favoritesEnabled ? params.get('favorites_of') ?? '' : '';
  const featured = favoritesEnabled && params.get('featured') === '1';
  const itemUlid = params.get('item') ?? params.get('media') ?? '';
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
  const selectedEvent = events.find((event) => event.ulid === eventUlid);
  const selectedAttendee = attendees.find((attendee) => String(attendee.id) === attendeeId);
  const selectableItems = items.filter((item) => item.upload_status === 'ready');
  const selectedItems = selectableItems.filter((item) => selectedIds.includes(item.id));
  const isEventIndex = section === 'events' && !attendeeIndex && !attendeeId;
  const showMedia = !isEventIndex && !attendeeIndex;
  const refresh = (): void => setRevision((value) => value + 1);
  useEffect(() => { setSelectedIds([]); }, [location]);
  useEffect(() => {
    setSelectedIds((current) => current.filter((id) => items.some((item) => item.id === id && item.upload_status === 'ready')));
  }, [items]);
  const replaceLocation = (url: string): void => {
    window.history.replaceState(window.history.state, '', url);
    setLocation(window.location.pathname + window.location.search);
  };
  const navigate = (url: string, scrollPosition = window.scrollY): void => {
    pendingScrollRestore.current = null;
    const target = new URL(url, window.location.origin);
    const targetItem = target.searchParams.get('item') ?? target.searchParams.get('media');
    if (targetItem && itemRef.current) { replaceLocation(url); return; }
    if (targetItem) { galleryScroll.current = scrollPosition; openedHere.current = true; }
    window.history.pushState(targetItem ? { eventViewer: true } : {}, '', url);
    setLocation(window.location.pathname + window.location.search);
  };
  const restoreScroll = (): void => {
    pendingScrollRestore.current = galleryScroll.current;
    window.requestAnimationFrame(() => window.scrollTo(0, galleryScroll.current));
  };
  const closeItem = (): void => {
    pendingEdge.current = null;
    if (openedHere.current && (window.history.state as { eventViewer?: boolean } | null)?.eventViewer) window.history.back();
    else { replaceLocation(pageUrl(params, { item: null })); restoreScroll(); }
    setListRevision((value) => value + 1);
  };
  useEffect(() => { itemRef.current = itemUlid; }, [itemUlid]);
  const captureGalleryPointer = (event: PointerEvent<HTMLElement>): void => {
    const anchor = (event.target as HTMLElement).closest('a');
    // Some mobile browsers scroll a link into focus before dispatching its click.
    galleryPointerStart.current = event.button === 0 && anchor ? { anchor, scroll: window.scrollY } : null;
  };
  const followInternalLink = (event: MouseEvent<HTMLElement>): void => {
    const pointerStart = galleryPointerStart.current;
    galleryPointerStart.current = null;
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const anchor = (event.target as HTMLElement).closest('a');
    if (!anchor || anchor.origin !== window.location.origin || !anchor.pathname.startsWith('/events') && !anchor.pathname.startsWith('/attendees')) return;
    event.preventDefault();
    navigate(anchor.pathname + anchor.search, event.detail > 0 && pointerStart?.anchor === anchor ? pointerStart.scroll : window.scrollY);
  };

  useEffect(() => {
    const popstate = (): void => {
      const next = new URLSearchParams(window.location.search);
      if (itemRef.current && !next.get('item') && !next.get('media')) {
        pendingEdge.current = null;
        pendingScrollRestore.current = galleryScroll.current;
        window.requestAnimationFrame(() => window.scrollTo(0, galleryScroll.current));
      }
      setLocation(window.location.pathname + window.location.search);
    };
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
    if (!showMedia || !viewerId) { pendingScrollRestore.current = null; return; }
    let active = true;
    let timer: number | undefined;
    let attempts = 0;
    const search = new URLSearchParams({ page: String(page) });
    if (eventUlid) search.set('event_ulid', eventUlid);
    if (section === 'mine') search.set('attendee_id', String(viewerId));
    else if (attendeeId) search.set('attendee_id', attendeeId);
    if (favoritesOf) search.set('favorites_of', favoritesOf);
    if (featured) search.set('featured', '1');
    const key = search.toString();
    const changed = previousListing.current !== key;
    previousListing.current = key;
    if (changed) { setLoading(true); setItems([]); setMeta(null); }
    setMediaError('');
    setListRefreshPaused(false);
    const load = async (): Promise<void> => {
      try {
        const result = await fetchWrapper.get(`/api/events/media?${key}`) as { data: EventMediaItem[]; meta: PageMeta };
        if (!active) return;
        setItems(result.data);
        setMeta(result.meta);
        if (pendingScrollRestore.current !== null) {
          const scroll = pendingScrollRestore.current;
          pendingScrollRestore.current = null;
          window.requestAnimationFrame(() => window.scrollTo(0, scroll));
        }
        const edge = pendingEdge.current;
        pendingEdge.current = null;
        if (edge) {
          const target = edge === 'first' ? result.data[0] : result.data.at(-1);
          const url = new URL(window.location.href);
          url.searchParams.delete('media');
          if (target) url.searchParams.set('item', target.ulid);
          else url.searchParams.delete('item');
          window.history.replaceState(window.history.state, '', url);
          setLocation(url.pathname + url.search);
        }
        if (result.data.some(mediaNeedsRefresh)) schedule();
      } catch (error) {
        if (!active) return;
        setItems([]); setMeta(null); setMediaError(eventError(error));
      } finally { if (active) setLoading(false); }
    };
    const schedule = (): void => {
      if (!active) return;
      if (attempts >= EVENT_REFRESH_LIMIT) { setListRefreshPaused(true); return; }
      timer = window.setTimeout(() => {
        attempts += 1;
        if (document.hidden) schedule();
        else void load();
      }, eventRefreshDelay(attempts));
    };
    void load();
    return () => { active = false; window.clearTimeout(timer); };
  }, [showMedia, viewerId, page, eventUlid, section, attendeeId, favoritesOf, featured, revision, listRevision]);

  useEffect(() => {
    let active = true;
    let timer: number | undefined;
    let attempts = 0;
    setActiveItem(null);
    setDetailError('');
    setDetailRefreshPaused(false);
    if (!itemUlid) { setDetailLoading(false); return; }
    setDetailLoading(true);
    const load = async (): Promise<void> => {
      try {
        const response = await fetchWrapper.get(`/api/events/media/${encodeURIComponent(itemUlid)}`) as { data: EventMediaItem };
        if (!active) return;
        setActiveItem(response.data);
        if (mediaNeedsRefresh(response.data)) schedule();
      } catch (error) {
        if (!active) return;
        setActiveItem(null);
        setItems((current) => current.filter((item) => item.ulid !== itemUlid));
        setDetailError(eventError(error));
      } finally { if (active) setDetailLoading(false); }
    };
    const schedule = (): void => {
      if (!active) return;
      if (attempts >= EVENT_REFRESH_LIMIT) { setDetailRefreshPaused(true); return; }
      timer = window.setTimeout(() => {
        attempts += 1;
        if (document.hidden) schedule();
        else void load();
      }, eventRefreshDelay(attempts));
    };
    void load();
    return () => { active = false; window.clearTimeout(timer); };
  }, [itemUlid, detailRevision]);

  const activeIndex = items.findIndex((item) => item.ulid === itemUlid);
  const browse = (direction: -1 | 1): void => {
    if (activeIndex < 0) return;
    const target = activeIndex >= 0 ? items[activeIndex + direction] : undefined;
    if (target) replaceLocation(pageUrl(params, { item: target.ulid }));
    else if (direction === -1 ? page > 1 : meta?.has_more) {
      pendingEdge.current = direction === -1 ? 'last' : 'first';
      replaceLocation(pageUrl(params, { page: String(page + direction) }));
    }
  };
  const copyJoiningLink = async (): Promise<void> => {
    if (!selectedEvent) return;
    try {
      await navigator.clipboard.writeText(`${window.location.origin}/events/join/${selectedEvent.ulid}`);
      setJoiningLinkFallback('');
      toast.success('Joining link copied. Attendees sign in and need access to this event.');
    } catch {
      setJoiningLinkFallback(`${window.location.origin}/events/join/${selectedEvent.ulid}`);
      toast.error('Clipboard access is unavailable. Select and copy the joining link below.');
    }
  };
  const allMediaUrl = pageUrl(params, { event_ulid: null, section: null, attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/events');
  const eventsUrl = pageUrl(params, { event_ulid: null, section: 'events', attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/events');
  const attendeesUrl = pageUrl(params, { section: null, attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/attendees');
  const featuredUrl = pageUrl(params, { section: null, attendee_id: null, favorites_of: null, featured: '1', page: null, item: null }, '/events');
  const mineUrl = pageUrl(params, { section: 'mine', attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/events');

  const curatorNames = settings?.featured_curators.map((curator) => curator.display_name).join(' & ') ?? '';
  const featuredLabel = curatorNames ? `${curatorNames}’s favorites` : 'Featured favorites';
  const filtersActive = !!attendeeId || !!favoritesOf || featured || section === 'mine';
  const filtersVisible = filtersOpen ?? filtersActive;
  const clearFiltersUrl = pageUrl(params, { attendee_id: null, favorites_of: null, featured: null, section: null, page: null, item: null }, '/events');
  const mediaHeading = featured ? featuredLabel : favoritesOf ? `${attendees.find((entry) => String(entry.id) === favoritesOf)?.display_name ?? 'Attendee'}’s favorites` : section === 'mine' ? 'My uploads' : selectedAttendee ? `Uploads by ${selectedAttendee.display_name}` : selectedEvent?.name ?? 'All photos & videos';

  return <div className={`mx-auto w-full ${BROWSING_PAGE_WIDTH} space-y-6 px-4 py-8`} onPointerDownCapture={captureGalleryPointer} onClick={followInternalLink}>
    <Toaster />
    <header className="flex flex-wrap items-start justify-between gap-4">
      <div><h1 className="text-2xl font-bold">Event photos & videos</h1><p className="mt-1 text-sm text-muted-foreground">Browse the moments attendees shared, or add your own.</p></div>
      {!initialLoading && !error && <MediaUploadDialog eventOptions={events} eventUlid={selectedEvent?.ulid} characters={[]} lastInterestIds={[]} onUploaded={refresh} triggerLabel="Add photos & videos" />}
    </header>
    <nav aria-label="Event navigation" className="flex flex-wrap items-center gap-2">
      <a href={allMediaUrl} aria-current={showMedia && !filtersActive && !eventUlid ? 'page' : undefined} className="rounded-md bg-muted px-3 py-2 text-sm hover:bg-accent">All media</a>
      <a href={eventsUrl} aria-current={isEventIndex ? 'page' : undefined} className="rounded-md bg-muted px-3 py-2 text-sm hover:bg-accent">Events</a>
      {favoritesEnabled && curatorNames && <a href={featuredUrl} aria-current={featured ? 'page' : undefined} className="rounded-md bg-muted px-3 py-2 text-sm hover:bg-accent">{featuredLabel}</a>}
      {moduleEnabled('library') && <a href="/library" className="ml-auto text-sm underline underline-offset-4">My collection</a>}
    </nav>
    {initialLoading ? <p role="status">Loading events…</p> : error ? <div role="alert" className="space-y-2 text-sm text-destructive"><p>{error}</p><Button variant="outline" onClick={refresh}>Try again</Button></div> : <>
      {(!selectedEvent || isEventIndex) && !filtersActive && !attendeeIndex && (events.length > 0 || isEventIndex) && <section aria-label="Available events" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {events.length === 0 && <p className="text-sm text-muted-foreground">No events are available yet. An organizer will add them here.</p>}
        {events.map((event) => <Card key={event.ulid}><CardContent className="grid gap-2 p-4"><h2 className="font-semibold"><a href={pageUrl(params, { event_ulid: event.ulid, section: null, page: null, item: null }, '/events')} className="block underline underline-offset-4">{event.name}</a></h2>{eventDate(event) && <p className="text-xs text-muted-foreground">{eventDate(event)}</p>}<p className="text-xs text-muted-foreground">{event.visibility === 'everyone' ? 'All attendees' : 'Selected attendees'}</p>{isEventIndex && event.description && <p className="text-sm">{event.description}</p>}{event.can_manage && isEventIndex && <Button size="sm" variant="outline" onClick={() => setEditor(event)}>Edit {event.name}</Button>}</CardContent></Card>)}
      </section>}
      {listRefreshPaused && <p role="status" className="text-sm text-muted-foreground">Automatic updates have paused. Choose Check for updates to look again.</p>}
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex min-w-0 max-w-full items-center gap-2"><Label htmlFor="event-filter" className="shrink-0">Event</Label><select id="event-filter" className="min-w-0 max-w-full flex-1 rounded-md border bg-background p-2" value={eventUlid} onChange={(event) => navigate(pageUrl(params, { event_ulid: event.target.value, section: null, page: null, item: null }))}><option value="">All events</option>{events.map((event) => <option key={event.ulid} value={event.ulid}>{event.name}</option>)}</select></div>
        <Button variant="outline" aria-expanded={filtersVisible} aria-controls="event-media-filters" onClick={() => setFiltersOpen((value) => !(value ?? filtersActive))}>Filter media</Button>
        {filtersActive && <a href={clearFiltersUrl} className="text-sm underline underline-offset-4">Clear filters</a>}
        <Button variant="ghost" size="sm" onClick={refresh}>Check for updates</Button>
      </div>
      {filtersVisible && <section id="event-media-filters" aria-label="Media filters" className="flex flex-wrap items-end gap-4 rounded border p-4">
        <div className="grid min-w-48 gap-1"><Label htmlFor="attendee-filter">Uploaded by</Label><select id="attendee-filter" className="rounded-md border bg-background p-2" value={section === 'mine' ? String(viewerId) : attendeeId} onChange={(event) => navigate(pageUrl(params, { attendee_id: event.target.value, section: null, page: null, item: null }, '/events'))}><option value="">All attendees</option>{attendees.map((attendee) => <option key={attendee.id} value={attendee.id}>{attendee.display_name}</option>)}</select></div>
        {favoritesEnabled && <div className="grid min-w-48 gap-1"><Label htmlFor="favorites-filter">Favorites by</Label><select id="favorites-filter" className="rounded-md border bg-background p-2" value={favoritesOf} onChange={(event) => navigate(pageUrl(params, { favorites_of: event.target.value, featured: null, page: null, item: null }, '/events'))}><option value="">Everyone / unfiltered</option>{attendees.map((attendee) => <option key={attendee.id} value={attendee.id}>{attendee.display_name}</option>)}</select></div>}
        <a href={mineUrl} className="text-sm underline underline-offset-4">My uploads</a><a href={attendeesUrl} className="text-sm underline underline-offset-4">Browse attendees</a>
      </section>}
      {selectedEvent && <section className="space-y-2 rounded border p-4">
        <div className="flex flex-wrap items-center justify-between gap-2"><h2 className="text-lg font-semibold">{selectedEvent.name}</h2><div className="flex flex-wrap gap-2"><Button size="sm" variant="outline" onClick={() => void copyJoiningLink()}>Copy joining link</Button>{selectedEvent.can_manage && <Button size="sm" variant="outline" onClick={() => setEditor(selectedEvent)}>Edit event</Button>}</div></div>
        {joiningLinkFallback === `${window.location.origin}/events/join/${selectedEvent.ulid}` && <div className="grid gap-1"><Label htmlFor="event-joining-link">Joining link</Label><Input id="event-joining-link" readOnly value={joiningLinkFallback} onFocus={(event) => event.target.select()} /></div>}
        {selectedEvent.description && <p className="whitespace-pre-wrap text-sm">{selectedEvent.description}</p>}
        {eventDate(selectedEvent) && <p className="text-sm text-muted-foreground">{eventDate(selectedEvent)}</p>}
        <Badge variant="outline">{selectedEvent.visibility === 'everyone' ? 'All attendees' : 'Selected attendees only'}</Badge>
        <p className="text-xs text-muted-foreground">New uploads appear to other attendees after review.</p>
      </section>}
      {eventUlid && !selectedEvent && <p role="alert" className="text-sm text-destructive">This event is unavailable or you do not have access.</p>}
      {attendeeIndex ? <section aria-label="Attendee galleries" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {attendees.length === 0 && <p>No attendee galleries are available yet.</p>}
        {attendees.map((attendee) => <Card key={attendee.id}><CardContent className="grid gap-3 p-4"><h2 className="font-semibold">{attendee.display_name}</h2><a className="text-sm underline" href={pageUrl(params, { section: null, favorites_of: null, page: null, item: null }, `/attendees/${attendee.id}`)}>View uploads by {attendee.display_name}</a>{favoritesEnabled && <a className="text-sm underline" href={pageUrl(params, { section: null, favorites_of: String(attendee.id), page: null, item: null }, '/events')}>View favorites by {attendee.display_name}</a>}</CardContent></Card>)}
      </section> : !isEventIndex && <section className="space-y-4" aria-label="Event media">
        <h2 className="text-lg font-semibold">{mediaHeading}</h2>
        {!loading && selectableItems.length > 0 && <Label className="flex items-center gap-2 text-sm"><Checkbox checked={selectedItems.length === selectableItems.length} indeterminate={selectedItems.length > 0 && selectedItems.length < selectableItems.length} onCheckedChange={(checked) => setSelectedIds(checked ? selectableItems.map((item) => item.id) : [])} />Select all on this page</Label>}
        {!loading && selectedItems.length > 0 && <BulkTransferActions source="events" ulids={selectedItems.map((item) => item.ulid)} downloadUlids={selectedItems.map((item) => item.ulid)} canSave={moduleEnabled('library') && selectedItems.some((item) => item.can_save_to_library)} onSaved={() => { setSelectedIds([]); refresh(); }} onClear={() => setSelectedIds([])} />}
        {mediaError ? <div role="alert" className="space-y-2 text-sm text-destructive"><p>{mediaError}</p><Button variant="outline" onClick={refresh}>Try again</Button></div> : loading ? <p role="status">Loading media…</p> : items.length === 0 ? <div className="space-y-3 rounded-lg border border-dashed p-6 text-center"><p className="font-medium">{filtersActive ? 'No moments match these filters.' : events.length === 0 ? 'No events are available yet.' : 'Be the first to share a moment.'}</p><p className="text-sm text-muted-foreground">{filtersActive ? 'Try another attendee or remove the filters to see more.' : events.length === 0 ? 'An organizer will make events available here.' : 'Choose Add photos & videos to contribute. New uploads will be reviewed before others see them.'}</p>{events.length === 0 && settings?.is_admin && <Button onClick={() => setEditor(null)}>Create event</Button>}{filtersActive && <a href={clearFiltersUrl} className="inline-block text-sm underline underline-offset-4">Show all media</a>}</div> : <MediaGrid items={items} selectedIds={selectedIds} onSelectionChange={setSelectedIds} canSelect={(item) => item.upload_status === 'ready'} getHref={(item) => pageUrl(params, { item: item.ulid })} renderActions={(media) => {
          const item = items.find((entry) => entry.id === media.id);
          return item && <div className="flex flex-wrap items-center gap-2">{favoritesEnabled && <FavoriteButton key={`${item.id}:${item.favorited}`} type="media" id={item.id} initialFavorited={item.favorited} />}<span className="text-xs text-muted-foreground">{item.uploader.display_name}{item.event ? ` · ${item.event.name}` : ''} · {eventMediaStatus(item)}</span></div>;
        }} />}
        {meta && meta.last_page > 1 && <nav className="flex items-center justify-between gap-3" aria-label="Media pages"><Button variant="outline" disabled={page <= 1 || loading} onClick={() => navigate(pageUrl(params, { page: String(page - 1), item: null }))}>Previous</Button><span className="text-sm">Page {meta.current_page} of {meta.last_page}</span><Button variant="outline" disabled={!meta.has_more || loading} onClick={() => navigate(pageUrl(params, { page: String(page + 1), item: null }))}>Next</Button></nav>}
      </section>}
      {settings?.is_admin && <details className="rounded border p-3"><summary className="cursor-pointer text-sm font-medium">Manage events</summary><div className="mt-3 flex flex-wrap gap-2"><Button onClick={() => setEditor(null)}>Create event</Button>{favoritesEnabled && <Button variant="outline" onClick={() => setCuratorsOpen(true)}>Manage featured curators</Button>}</div></details>}
    </>}
    {detailLoading && <p role="status">Loading media details…</p>}
    {detailError && <div role="alert" className="flex items-center gap-3 text-sm text-destructive"><p>{detailError}</p><Button variant="outline" onClick={() => setDetailRevision((value) => value + 1)}>Try again</Button><Button variant="outline" onClick={closeItem}>Dismiss</Button></div>}
    {activeItem && <EventMediaDialog key={activeItem.ulid} item={activeItem} onClose={closeItem} onDeleted={refresh} onNavigate={browse} hasPrevious={activeIndex >= 0 && (activeIndex > 0 || page > 1)} hasNext={activeIndex >= 0 && (activeIndex < items.length - 1 || !!meta?.has_more)} navigationBusy={loading || detailLoading} position={activeIndex >= 0 ? `Item ${(page - 1) * (meta?.per_page ?? items.length) + activeIndex + 1}${meta ? ` of ${meta.total}` : ''}` : 'Shared media'} refreshPaused={detailRefreshPaused} onRefresh={() => setDetailRevision((value) => value + 1)} />}
    {editor !== undefined && <EventEditor event={editor} attendees={attendees} onClose={() => setEditor(undefined)} onSaved={refresh} />}
    {curatorsOpen && settings && <CuratorEditor settings={settings} attendees={attendees} onClose={() => setCuratorsOpen(false)} onSaved={refresh} />}
  </div>;
}

const element = document.getElementById('events');
if (element) createRoot(element).render(<EventsPage />);
