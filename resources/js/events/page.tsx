import { ChevronDown, ImagePlus, Layers, LayoutGrid, Link2, List, Pencil, RefreshCw, SlidersHorizontal, Star } from 'lucide-react';
import { type MouseEvent, type PointerEvent, type ReactNode, useEffect, useRef, useState } from 'react';
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
import { gallerySections } from '@/events/capture-time';
import { CuratorEditor, EventEditor, EventMediaDialog } from '@/events/EventDialogs';
import { EVENT_REFRESH_LIMIT, eventMediaStatus, eventRefreshDelay, mediaNeedsRefresh } from '@/events/media-status';
import type { ReviewAction } from '@/events/ReviewPanel';
import { registerShareTarget, takeSharedFiles } from '@/events/sharedFiles';
import { type EventAttendee, eventError, type EventMediaItem, type EventProfile, type EventSettings } from '@/events/types';
import { fetchWrapper } from '@/fetchWrapper';
import { moduleEnabled } from '@/instance';
import { BulkTransferActions } from '@/media/BulkTransferActions';
import { MediaGrid } from '@/media/MediaGrid';
import { MediaThumbnailGrid } from '@/media/MediaThumbnailGrid';
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

type GalleryView = 'gallery' | 'details';

const GALLERY_VIEW_KEY = 'vora:events:view';

function storedGalleryView(): GalleryView {
  try { return localStorage.getItem(GALLERY_VIEW_KEY) === 'details' ? 'details' : 'gallery'; } catch { return 'gallery'; }
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
  const [view, setView] = useState<GalleryView>(storedGalleryView);
  const [selecting, setSelecting] = useState(false);
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
  const recent = params.get('sort') === 'recent';
  const reviewMode = params.get('review') === '1';
  const itemUlid = params.get('item') ?? params.get('media') ?? '';
  const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
  const selectedEvent = events.find((event) => event.ulid === eventUlid);
  const selectedAttendee = attendees.find((attendee) => String(attendee.id) === attendeeId);
  // The review queue holds other people's unapproved uploads, which bulk Save and ZIP
  // downloads cannot include; review there is one item at a time.
  const canSelectItem = (item: { upload_status: string }): boolean => !reviewMode && item.upload_status === 'ready';
  const selectableItems = items.filter(canSelectItem);
  const selectedItems = selectableItems.filter((item) => selectedIds.includes(item.id));
  const isEventIndex = section === 'events' && !attendeeIndex && !attendeeId;
  const showMedia = !isEventIndex && !attendeeIndex;
  const refresh = (): void => setRevision((value) => value + 1);
  useEffect(() => { setSelectedIds([]); }, [location]);
  const changeView = (next: GalleryView): void => {
    setView(next);
    setSelecting(false);
    setSelectedIds([]);
    try { localStorage.setItem(GALLERY_VIEW_KEY, next); } catch { /* Per-viewer convenience only. */ }
  };
  useEffect(() => {
    setSelectedIds((current) => current.filter((id) => items.some((item) => item.id === id && item.upload_status === 'ready')));
  }, [items]);
  const replaceLocation = (url: string): void => {
    window.history.replaceState(window.history.state, '', url);
    setLocation(window.location.pathname + window.location.search);
  };
  const [sharedFiles, setSharedFiles] = useState<File[]>();
  useEffect(() => {
    registerShareTarget();
    if (new URLSearchParams(window.location.search).get('shared') !== '1') return;
    replaceLocation(pageUrl(new URLSearchParams(window.location.search), { shared: null }));
    void takeSharedFiles().then((files) => { if (files.length > 0) setSharedFiles(files); }).catch(() => undefined);
     
  }, []);
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
    if (recent) search.set('sort', 'recent');
    if (reviewMode) search.set('review', '1');
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
        // Moderating or deleting the last item on a later page empties it; step back
        // to the last page that still has items instead of claiming none remain.
        if (result.data.length === 0 && page > 1 && result.meta.last_page < page) {
          replaceLocation(pageUrl(new URLSearchParams(window.location.search), { page: result.meta.last_page > 1 ? String(result.meta.last_page) : null }));
          return;
        }
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
  }, [showMedia, viewerId, page, eventUlid, section, attendeeId, favoritesOf, featured, recent, reviewMode, revision, listRevision]);

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

  const concreteIndex = items.findIndex((item) => item.ulid === itemUlid);
  const activeGroup = activeItem?.photo_versions?.preferred_ulid;
  const activeIndex = concreteIndex >= 0 ? concreteIndex : activeGroup ? items.findIndex((item) => item.photo_versions?.preferred_ulid === activeGroup || item.ulid === activeGroup) : -1;
  const browse = (direction: -1 | 1): void => {
    if (activeIndex < 0) return;
    const target = activeIndex >= 0 ? items[activeIndex + direction] : undefined;
    if (target) replaceLocation(pageUrl(params, { item: target.ulid }));
    else if (direction === -1 ? page > 1 : meta?.has_more) {
      pendingEdge.current = direction === -1 ? 'last' : 'first';
      replaceLocation(pageUrl(params, { page: String(page + direction) }));
    }
  };
  // In the review queue, move straight to the next waiting item.
  const moderated = (action: ReviewAction): void => {
    toast.success(action === 'approve' ? 'Approved. Attendees can now see it.' : 'Rejected.');
    const next = reviewMode && activeIndex >= 0 ? items[activeIndex + 1] ?? items[activeIndex - 1] : undefined;
    if (next) replaceLocation(pageUrl(params, { item: next.ulid }));
    else if (reviewMode) closeItem();
    else setDetailRevision((value) => value + 1);
    refresh();
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
  const allMediaUrl = pageUrl(params, { review: null, event_ulid: null, section: null, attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/events');
  const eventsUrl = pageUrl(params, { review: null, event_ulid: null, section: 'events', attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/events');
  const attendeesUrl = pageUrl(params, { review: null, section: null, attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/attendees');
  const featuredUrl = pageUrl(params, { review: null, section: null, attendee_id: null, favorites_of: null, featured: '1', page: null, item: null }, '/events');
  const mineUrl = pageUrl(params, { review: null, section: 'mine', attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/events');

  const curatorNames = settings?.featured_curators.map((curator) => curator.display_name).join(' & ') ?? '';
  const featuredLabel = curatorNames ? `${curatorNames}’s favorites` : 'Featured favorites';
  const filtersActive = !!attendeeId || !!favoritesOf || featured || section === 'mine';
  const filtersVisible = filtersOpen ?? filtersActive;
  const reviewUrl = pageUrl(params, { review: '1', section: null, attendee_id: null, favorites_of: null, featured: null, page: null, item: null }, '/events');
  const pendingReviewCount = settings?.pending_review_count ?? 0;
  const clearFiltersUrl = pageUrl(params, { review: null, attendee_id: null, favorites_of: null, featured: null, section: null, page: null, item: null }, '/events');
  const mediaHeading = reviewMode ? 'Awaiting review' : featured ? featuredLabel : favoritesOf ? `${attendees.find((entry) => String(entry.id) === favoritesOf)?.display_name ?? 'Attendee'}’s favorites` : section === 'mine' ? 'My uploads' : selectedAttendee ? `Uploads by ${selectedAttendee.display_name}` : selectedEvent?.name ?? 'All photos & videos';

  const tabClassName = 'shrink-0 whitespace-nowrap rounded-full bg-muted px-3 py-1.5 text-sm hover:bg-accent aria-[current=page]:bg-foreground aria-[current=page]:text-background';
  const showEventHeading = !!selectedEvent && mediaHeading === selectedEvent.name;
  const hasSelection = !loading && selectedItems.length > 0;
  const renderThumbnails = (list: EventMediaItem[]): ReactNode => <MediaThumbnailGrid items={list} selecting={selecting} selectedIds={selectedIds} onSelectionChange={setSelectedIds} canSelect={canSelectItem} getHref={(item) => pageUrl(params, { item: item.ulid })} renderOverlay={(media) => {
    const item = items.find((entry) => entry.id === media.id);
    if (!item) return null;
    const status = eventMediaStatus(item);
    return <>
      {item.photo_versions && <span className="pointer-events-none absolute left-1 top-1 flex items-center gap-0.5 rounded bg-black/65 px-1 py-0.5 text-[11px] text-white"><Layers aria-hidden="true" className="size-3" /><span aria-hidden="true">{item.photo_versions.visible_count}</span><span className="sr-only">{item.photo_versions.visible_count} versions{item.photo_versions.is_preferred ? ' · Preferred' : ''}</span></span>}
      {status !== 'Shared' && <span className="pointer-events-none absolute bottom-1 left-1 max-w-[calc(100%-2rem)] truncate rounded bg-black/65 px-1.5 py-0.5 text-[11px] text-white">{status}</span>}
      {item.favorited && <span className="pointer-events-none absolute bottom-1 right-1 text-yellow-300 drop-shadow"><Star aria-hidden="true" className="size-4 fill-current" /><span className="sr-only">Favorited</span></span>}
    </>;
  }} />;
  const renderBulkActions = (compact: boolean): ReactNode => <BulkTransferActions compact={compact} source="events" ulids={selectedItems.map((item) => item.ulid)} downloadUlids={selectedItems.map((item) => item.ulid)} canSave={moduleEnabled('library') && selectedItems.some((item) => item.can_save_to_library)} onSaved={() => { setSelectedIds([]); refresh(); }} onClear={() => setSelectedIds([])} />;

  return <div className={`mx-auto w-full ${BROWSING_PAGE_WIDTH} space-y-4 sm:space-y-6 sm:px-4 sm:py-8`} onPointerDownCapture={captureGalleryPointer} onClick={followInternalLink}>
    <Toaster />
    <header className="flex flex-wrap items-end justify-between gap-3">
      <div className="min-w-0"><h1 className="text-xl font-bold sm:text-2xl">Event photos & videos</h1><p className="mt-1 hidden text-sm text-muted-foreground sm:block">Browse the moments attendees shared, or add your own.</p></div>
      {/* Full-width on phones so the primary action is a large thumb target. */}
      {!initialLoading && !error && <div className="w-full sm:w-auto [&_button]:w-full sm:[&_button]:w-auto"><MediaUploadDialog eventOptions={events} eventUlid={selectedEvent?.ulid} eventUploadsRequireReview={settings?.uploads_require_review ?? true} sharedFiles={sharedFiles} characters={[]} lastInterestIds={[]} onUploaded={refresh} triggerLabel={<><ImagePlus aria-hidden="true" />Add photos & videos</>} /></div>}
    </header>
    {/* One scrollable row on phones instead of wrapping onto several lines. */}
    <nav aria-label="Event navigation" className="-mx-4 flex items-center gap-2 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:px-0">
      <a href={allMediaUrl} aria-current={showMedia && !filtersActive && !eventUlid && !reviewMode ? 'page' : undefined} className={tabClassName}>All media</a>
      <a href={eventsUrl} aria-current={isEventIndex ? 'page' : undefined} className={tabClassName}>Events</a>
      {favoritesEnabled && curatorNames && <a href={featuredUrl} aria-current={featured ? 'page' : undefined} className={tabClassName}>{featuredLabel}</a>}
      {settings?.is_admin && (pendingReviewCount > 0 || reviewMode) && <a href={reviewUrl} aria-current={reviewMode ? 'page' : undefined} className={tabClassName}>Awaiting review ({pendingReviewCount})</a>}
      {moduleEnabled('library') && <a href="/library" className="ml-auto shrink-0 whitespace-nowrap pl-2 text-sm underline underline-offset-4">My collection</a>}
    </nav>
    {initialLoading ? <p role="status">Loading events…</p> : error ? <div role="alert" className="space-y-2 text-sm text-destructive"><p>{error}</p><Button variant="outline" onClick={refresh}>Try again</Button></div> : <>
      {(!selectedEvent || isEventIndex) && !filtersActive && !attendeeIndex && (events.length > 0 || isEventIndex) && <section aria-label="Available events" className={isEventIndex ? 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3' : '-mx-4 flex snap-x gap-3 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-2 sm:px-0 lg:grid-cols-3'}>
        {events.length === 0 && <p className="text-sm text-muted-foreground">No events are available yet. An organizer will add them here.</p>}
        {events.map((event) => <Card key={event.ulid} className={isEventIndex ? undefined : 'w-56 shrink-0 snap-start py-0 sm:w-auto'}><CardContent className="grid gap-2 p-4"><h2 className="font-semibold"><a href={pageUrl(params, { event_ulid: event.ulid, section: null, page: null, item: null }, '/events')} className="block underline underline-offset-4">{event.name}</a></h2>{eventDate(event) && <p className="text-xs text-muted-foreground">{eventDate(event)}</p>}<p className="text-xs text-muted-foreground">{event.visibility === 'everyone' ? 'All attendees' : 'Selected attendees'}</p>{isEventIndex && event.description && <p className="text-sm">{event.description}</p>}{event.can_manage && isEventIndex && <Button size="sm" variant="outline" onClick={() => setEditor(event)}>Edit {event.name}</Button>}</CardContent></Card>)}
      </section>}
      {listRefreshPaused && <p role="status" className="text-sm text-muted-foreground">Automatic updates have paused. Choose Check for updates to look again.</p>}
      {/* The Event picker doubles as the section title; its actions sit beside it as icons. */}
      <div className="flex flex-wrap items-center gap-1 sm:gap-2">
        <div className="relative min-w-0 flex-1 sm:max-w-sm">
          <Label htmlFor="event-filter" className="sr-only">Event</Label>
          <select id="event-filter" className="h-10 w-full min-w-0 appearance-none truncate rounded-md bg-transparent pl-1 pr-7 text-lg font-semibold hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" value={eventUlid} onChange={(event) => navigate(pageUrl(params, { event_ulid: event.target.value, section: null, page: null, item: null }))}><option value="">All events</option>{events.map((event) => <option key={event.ulid} value={event.ulid}>{event.name}</option>)}</select>
          <ChevronDown aria-hidden="true" className="pointer-events-none absolute right-1.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        </div>
        {selectedEvent && <Button variant="ghost" size="icon" aria-label="Copy joining link" title="Copy joining link" onClick={() => void copyJoiningLink()}><Link2 aria-hidden="true" /></Button>}
        {selectedEvent?.can_manage && <Button variant="ghost" size="icon" aria-label="Edit event" title="Edit event" onClick={() => setEditor(selectedEvent)}><Pencil aria-hidden="true" /></Button>}
        <Button variant="ghost" size="icon" className="relative" aria-label="Filter media" title="Filter media" aria-expanded={filtersVisible} aria-controls="event-media-filters" onClick={() => setFiltersOpen((value) => !(value ?? filtersActive))}><SlidersHorizontal aria-hidden="true" />{filtersActive && <span aria-hidden="true" className="absolute right-1.5 top-1.5 size-2 rounded-full bg-primary" />}</Button>
        <Button variant="ghost" size="icon" aria-label="Check for updates" title="Check for updates" onClick={refresh}><RefreshCw aria-hidden="true" /></Button>
        {filtersActive && <a href={clearFiltersUrl} className="basis-full whitespace-nowrap text-sm underline underline-offset-4 sm:basis-auto">Clear filters</a>}
      </div>
      {filtersVisible && <section id="event-media-filters" aria-label="Media filters" className="flex flex-wrap items-end gap-4 rounded border p-4">
        <div className="grid min-w-48 gap-1"><Label htmlFor="attendee-filter">Uploaded by</Label><select id="attendee-filter" className="rounded-md border bg-background p-2" value={section === 'mine' ? String(viewerId) : attendeeId} onChange={(event) => navigate(pageUrl(params, { attendee_id: event.target.value, section: null, page: null, item: null }, '/events'))}><option value="">All attendees</option>{attendees.map((attendee) => <option key={attendee.id} value={attendee.id}>{attendee.display_name}</option>)}</select></div>
        {favoritesEnabled && <div className="grid min-w-48 gap-1"><Label htmlFor="favorites-filter">Favorites by</Label><select id="favorites-filter" className="rounded-md border bg-background p-2" value={favoritesOf} onChange={(event) => navigate(pageUrl(params, { favorites_of: event.target.value, featured: null, page: null, item: null }, '/events'))}><option value="">Everyone / unfiltered</option>{attendees.map((attendee) => <option key={attendee.id} value={attendee.id}>{attendee.display_name}</option>)}</select></div>}
        <a href={mineUrl} className="text-sm underline underline-offset-4">My uploads</a><a href={attendeesUrl} className="text-sm underline underline-offset-4">Browse attendees</a>
      </section>}
      {/* The picker above shows the name; this keeps it in the heading outline and adds only what is informative. */}
      {selectedEvent && <section aria-label="Event details" className="space-y-2">
        <h2 className="sr-only">{selectedEvent.name}</h2>
        {(eventDate(selectedEvent) || selectedEvent.visibility !== 'everyone') && <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">{eventDate(selectedEvent) && <span>{eventDate(selectedEvent)}</span>}{selectedEvent.visibility !== 'everyone' && <Badge variant="outline">Selected attendees only</Badge>}</div>}
        {joiningLinkFallback === `${window.location.origin}/events/join/${selectedEvent.ulid}` && <div className="grid gap-1"><Label htmlFor="event-joining-link">Joining link</Label><Input id="event-joining-link" readOnly value={joiningLinkFallback} onFocus={(event) => event.target.select()} /></div>}
        {selectedEvent.description && <p className="whitespace-pre-wrap text-sm">{selectedEvent.description}</p>}
      </section>}
      {eventUlid && !selectedEvent && <p role="alert" className="text-sm text-destructive">This event is unavailable or you do not have access.</p>}
      {attendeeIndex ? <section aria-label="Attendee galleries" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {attendees.length === 0 && <p>No attendee galleries are available yet.</p>}
        {attendees.map((attendee) => <Card key={attendee.id}><CardContent className="grid gap-3 p-4"><h2 className="font-semibold">{attendee.display_name}</h2><a className="text-sm underline" href={pageUrl(params, { section: null, favorites_of: null, page: null, item: null }, `/attendees/${attendee.id}`)}>View uploads by {attendee.display_name}</a>{favoritesEnabled && <a className="text-sm underline" href={pageUrl(params, { section: null, favorites_of: String(attendee.id), page: null, item: null }, '/events')}>View favorites by {attendee.display_name}</a>}</CardContent></Card>)}
      </section> : !isEventIndex && <section className="space-y-4" aria-label="Event media">
        <div className="flex flex-wrap items-center gap-2">
          {showEventHeading ? <p className="mr-auto text-sm text-muted-foreground">{!loading && meta ? `${meta.total} ${meta.total === 1 ? 'item' : 'items'}` : ''}</p> : <h2 className="mr-auto min-w-0 truncate text-lg font-semibold">{mediaHeading}</h2>}
          <div className="ml-auto flex items-center gap-2">
            <select aria-label="Sort media" className="h-8 rounded-md border bg-background px-2 text-sm" value={recent ? 'recent' : ''} onChange={(event) => navigate(pageUrl(params, { sort: event.target.value || null, page: null, item: null }))}><option value="">Time taken</option><option value="recent">Recently added</option></select>
            {view === 'gallery' && !loading && selectableItems.length > 0 && <Button size="sm" variant={selecting ? 'default' : 'outline'} aria-pressed={selecting} onClick={() => { if (selecting) setSelectedIds([]); setSelecting(!selecting); }}>{selecting ? 'Cancel' : 'Select'}</Button>}
            <div role="group" aria-label="Layout" className="inline-flex overflow-hidden rounded-md border">
              <Button variant="ghost" size="icon-sm" className="rounded-none aria-pressed:bg-muted" aria-label="Gallery view" title="Gallery view" aria-pressed={view === 'gallery'} onClick={() => changeView('gallery')}><LayoutGrid aria-hidden="true" /></Button>
              <Button variant="ghost" size="icon-sm" className="rounded-none aria-pressed:bg-muted" aria-label="Details view" title="Details view" aria-pressed={view === 'details'} onClick={() => changeView('details')}><List aria-hidden="true" /></Button>
            </div>
          </div>
        </div>
        {!loading && selectableItems.length > 0 && (view === 'details' || selecting) && <Label className="flex w-fit items-center gap-2 text-sm text-muted-foreground"><Checkbox checked={selectedItems.length === selectableItems.length} indeterminate={selectedItems.length > 0 && selectedItems.length < selectableItems.length} onCheckedChange={(checked) => setSelectedIds(checked ? selectableItems.map((item) => item.id) : [])} />Select all on this page</Label>}
        {view === 'details' && hasSelection && renderBulkActions(false)}
        {mediaError ? <div role="alert" className="space-y-2 text-sm text-destructive"><p>{mediaError}</p><Button variant="outline" onClick={refresh}>Try again</Button></div> : loading ? <p role="status">Loading media…</p> : items.length === 0 && reviewMode ? <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">Nothing is waiting for review.</p> : items.length === 0 ? <div className="space-y-3 rounded-lg border border-dashed p-6 text-center"><p className="font-medium">{filtersActive ? 'No moments match these filters.' : events.length === 0 ? 'No events are available yet.' : 'Be the first to share a moment.'}</p><p className="text-sm text-muted-foreground">{filtersActive ? 'Try another attendee or remove the filters to see more.' : events.length === 0 ? 'An organizer will make events available here.' : 'Choose Add photos & videos to contribute.'}</p>{events.length === 0 && settings?.is_admin && <Button onClick={() => setEditor(null)}>Create event</Button>}{filtersActive && <a href={clearFiltersUrl} className="inline-block text-sm underline underline-offset-4">Show all media</a>}</div> : view === 'gallery' ? (recent ? renderThumbnails(items) : <div className="space-y-4">{gallerySections(items, selectedEvent?.timezone).map((section) => <section key={section.key} aria-label={section.label} className="space-y-1">
          <h3 className="sticky top-0 z-10 -mx-4 flex items-baseline justify-between gap-2 bg-background/95 px-4 py-1.5 text-sm font-semibold backdrop-blur sm:mx-0 sm:px-0"><span>{section.label}</span>{section.span && <span className="text-xs font-normal text-muted-foreground">{section.span}</span>}</h3>
          {renderThumbnails(section.items)}
        </section>)}</div>) : <MediaGrid items={items} thumbnailSize="compact" selectedIds={selectedIds} onSelectionChange={setSelectedIds} canSelect={canSelectItem} getHref={(item) => pageUrl(params, { item: item.ulid })} renderActions={(media) => {
          const item = items.find((entry) => entry.id === media.id);
          return item && <div className="flex min-w-0 flex-wrap items-center gap-2">{favoritesEnabled && <FavoriteButton key={`${item.id}:${item.favorited}`} type="media" id={item.id} initialFavorited={item.favorited} />}<span className="min-w-0 break-words text-xs text-muted-foreground">{item.uploader.display_name}{item.event && !eventUlid ? ` · ${item.event.name}` : ''} · {eventMediaStatus(item)}</span>{item.photo_versions && <Badge variant="secondary">{item.photo_versions.visible_count} versions{item.photo_versions.is_preferred ? ' · Preferred' : ''}</Badge>}</div>;
        }} />}
        {/* Below the grid and pinned to the viewport, so tapping a tile never shifts the tiles. */}
        {view === 'gallery' && hasSelection && <div className="sticky bottom-2 z-20 rounded bg-background shadow-lg">{renderBulkActions(true)}</div>}
        {meta && meta.last_page > 1 && <nav className="flex items-center justify-between gap-3" aria-label="Media pages"><Button variant="outline" disabled={page <= 1 || loading} onClick={() => navigate(pageUrl(params, { page: String(page - 1), item: null }))}>Previous</Button><span className="text-sm">Page {meta.current_page} of {meta.last_page}</span><Button variant="outline" disabled={!meta.has_more || loading} onClick={() => navigate(pageUrl(params, { page: String(page + 1), item: null }))}>Next</Button></nav>}
      </section>}
      {settings?.is_admin && <details className="rounded border p-3"><summary className="cursor-pointer text-sm font-medium">Manage events</summary><div className="mt-3 flex flex-wrap items-center gap-2"><Button onClick={() => setEditor(null)}>Create event</Button><a className="text-sm underline" href="/events/organizer">Manage attendees</a>{favoritesEnabled && <Button variant="outline" onClick={() => setCuratorsOpen(true)}>Manage featured curators</Button>}</div></details>}
    </>}
    {detailLoading && <p role="status">Loading media details…</p>}
    {detailError && <div role="alert" className="flex items-center gap-3 text-sm text-destructive"><p>{detailError}</p><Button variant="outline" onClick={() => setDetailRevision((value) => value + 1)}>Try again</Button><Button variant="outline" onClick={closeItem}>Dismiss</Button></div>}
    {activeItem && <EventMediaDialog key={activeItem.ulid} item={activeItem} onClose={closeItem} onDeleted={refresh} onNavigate={browse} onChooseVersion={(ulid) => replaceLocation(pageUrl(params, { item: ulid }))} hasPrevious={activeIndex >= 0 && (activeIndex > 0 || page > 1)} hasNext={activeIndex >= 0 && (activeIndex < items.length - 1 || !!meta?.has_more)} navigationBusy={loading || detailLoading} position={activeIndex >= 0 ? `Item ${(page - 1) * (meta?.per_page ?? items.length) + activeIndex + 1}${meta ? ` of ${meta.total}` : ''}` : 'Shared media'} refreshPaused={detailRefreshPaused} onRefresh={() => setDetailRevision((value) => value + 1)} onModerated={moderated} />}
    {editor !== undefined && <EventEditor event={editor} attendees={attendees} onClose={() => setEditor(undefined)} onSaved={refresh} />}
    {curatorsOpen && settings && <CuratorEditor settings={settings} attendees={attendees} onClose={() => setCuratorsOpen(false)} onSaved={refresh} />}
  </div>;
}

const element = document.getElementById('events');
if (element) createRoot(element).render(<EventsPage />);
