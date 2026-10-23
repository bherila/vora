import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { EVENT_REFRESH_LIMIT, eventRefreshDelay } from '@/events/media-status';
import { EventsPage } from '@/events/page';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn(), patch: jest.fn(), put: jest.fn(), delete: jest.fn() } }));
jest.mock('@/initialData', () => ({ readInitialData: jest.fn() }));
jest.mock('sonner', () => ({ Toaster: () => null, toast: { success: jest.fn(), error: jest.fn() } }));
jest.mock('@/components/favorite-button', () => ({ FavoriteButton: () => <button>Favorite</button> }));
jest.mock('@/library/SaveToLibraryButton', () => ({ SaveToLibraryButton: () => <button>Save to Library</button> }));
jest.mock('@/media/MediaPlayer', () => ({ MediaPlayer: ({ item }: { item: { video: { master_url: string } } }) => <div data-testid="player">{item.video?.master_url}</div> }));
jest.mock('@/media/MediaUploadDialog', () => ({ MediaUploadDialog: ({ eventUlid, eventOptions, triggerLabel }: { eventUlid?: string; eventOptions: unknown[]; triggerLabel: string }) => <button data-event={eventUlid ?? ''} data-options={eventOptions.length}>{triggerLabel}</button> }));

const event = { ulid: 'event-one', name: 'Welcome', description: 'Welcome gathering', visibility: 'everyone', timezone: 'UTC', starts_at: null, ends_at: null, can_manage: true, member_ids: [] };
const attendees = [{ id: 3, display_name: 'Alex' }, { id: 7, display_name: 'Sam' }];
const settings = { is_admin: true, viewer_id: 3, featured_curators: [attendees[1]] };
const media = { id: 11, ulid: 'media-one', character_id: null, type: 'video', purpose: 'gallery', title: 'Welcome video', mime_type: 'video/mp4', size_bytes: 5, audience: 'specific', discoverable: false, upload_status: 'ready', url: null, thumbnail_url: null, video: null, interests: [], character: null, created_at: null, event: { ulid: event.ulid, name: event.name }, uploader: attendees[1], owned: false, download_url: null, favorited: false };
const meta = { current_page: 1, last_page: 2, per_page: 24, total: 25, has_more: true };

function mockRequests() {
  jest.mocked(fetchWrapper.get).mockImplementation(async (url) => {
    if (url === '/api/events') return { data: [event] };
    if (url === '/api/events/attendees') return { data: attendees };
    if (url === '/api/events/settings') return { data: settings };
    if (url.startsWith('/api/events/media?')) return { data: [media], meta };
    if (url === '/api/events/media/media-one') return { data: { ...media, video: { status: 'ready', master_url: '/api/events/hls/master.m3u8' }, download_url: '/api/events/media/media-one/download', can_save_to_library: true } };
    throw new Error(`Unexpected URL ${url}`);
  });
}

describe('EventsPage', () => {
  beforeEach(() => { jest.resetAllMocks(); Object.defineProperty(window, 'PointerEvent', { configurable: true, value: MouseEvent }); window.scrollTo = jest.fn(); jest.mocked(readInitialData).mockReturnValue({}); window.history.replaceState({}, '', '/events'); mockRequests(); });

  it('loads visible events and requires an explicit event before upload', async () => {
    render(<EventsPage />);
    expect(screen.getByRole('status')).toHaveTextContent('Loading events');
    expect(await screen.findByText('Welcome video')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add photos & videos' })).toHaveAttribute('data-options', '1');
    expect(screen.queryByLabelText('Uploaded by')).toBeNull();
    expect(screen.queryByLabelText('Favorites by')).toBeNull();
    fireEvent.change(screen.getByLabelText('Event'), { target: { value: 'event-one' } });
    expect(await screen.findByRole('button', { name: 'Add photos & videos' })).toHaveAttribute('data-event', 'event-one');
    expect(screen.getByText('All attendees', { selector: '[data-slot=badge]' })).toBeInTheDocument();
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith('/api/events/media?page=1&event_ulid=event-one'));
  });

  it('selects current-page ready media, saves it privately and resets selection on filters', async () => {
    const implementation = jest.mocked(fetchWrapper.get).getMockImplementation()!;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => url.startsWith('/api/events/media?') ? {
      data: [{ ...media, can_save_to_library: true }, { ...media, id: 12, ulid: 'pending-one', title: 'Pending upload', upload_status: 'pending' }], meta,
    } : implementation(url));
    jest.mocked(fetchWrapper.post).mockRejectedValueOnce('Storage limit reached.');
    render(<EventsPage />);
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Select all on this page' }));
    expect(screen.getByText('1 selected')).toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: 'Select Pending upload' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Save selected to Library' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Storage limit reached');
    expect(fetchWrapper.post).toHaveBeenCalledWith('/api/events/media/bulk-save', { ulids: ['media-one'] });
    fireEvent.change(screen.getByLabelText('Event'), { target: { value: 'event-one' } });
    await waitFor(() => expect(screen.queryByLabelText('Save or download selected items')).toBeNull());
    expect(await screen.findByRole('checkbox', { name: 'Select Welcome video' })).not.toBeChecked();
  });

  it('retains event and favorite filters through pagination and browser history', async () => {
    window.history.replaceState({}, '', '/events?event_ulid=event-one&favorites_of=7');
    render(<EventsPage />);
    await screen.findByText('Welcome video');
    expect(screen.getByLabelText('Favorites by')).toHaveValue('7');
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith('/api/events/media?page=2&event_ulid=event-one&favorites_of=7'));
    act(() => { window.history.replaceState({}, '', '/events?event_ulid=event-one&favorites_of=7'); window.dispatchEvent(new PopStateEvent('popstate')); });
    await waitFor(() => expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled());
  });

  it('offers attendee uploads and their visible favorites without exposing account email', async () => {
    window.history.replaceState({}, '', '/attendees?event_ulid=event-one');
    render(<EventsPage />);
    const link = await screen.findByRole('link', { name: 'View uploads by Sam' });
    expect(link).toHaveAttribute('href', '/attendees/7?event_ulid=event-one');
    fireEvent.click(link);
    expect(await screen.findByRole('heading', { name: 'Uploads by Sam' })).toBeInTheDocument();
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith('/api/events/media?page=1&event_ulid=event-one&attendee_id=7'));
    fireEvent.click(screen.getByRole('link', { name: 'Sam’s favorites' }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith('/api/events/media?page=1&event_ulid=event-one&featured=1'));
    expect(await screen.findByRole('heading', { name: 'Sam’s favorites' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('link', { name: 'My uploads' }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith('/api/events/media?page=1&event_ulid=event-one&attendee_id=3'));
    expect(screen.getByRole('link', { name: 'My collection' })).toHaveAttribute('href', '/library');
  });

  it('fetches details before playing a card and exposes download and private Save', async () => {
    render(<EventsPage />);
    fireEvent.click(await screen.findByRole('link', { name: 'Open Welcome video' }));
    expect(await screen.findByTestId('player')).toHaveTextContent('/api/events/hls/master.m3u8');
    expect(fetchWrapper.get).toHaveBeenCalledWith('/api/events/media/media-one');
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByRole('link', { name: 'Download' })).toHaveAttribute('href', '/api/events/media/media-one/download');
    expect(within(dialog).getByRole('button', { name: 'Save to Library' })).toBeInTheDocument();
    expect(within(dialog).queryByRole('button', { name: 'Delete my upload' })).toBeNull();
  });

  it('opens favorite links and clears a denied media request without starting playback', async () => {
    window.history.replaceState({}, '', '/events?media=forbidden');
    const implementation = jest.mocked(fetchWrapper.get).getMockImplementation()!;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => url === '/api/events/media/forbidden' ? Promise.reject('You cannot view this media.') : implementation(url));
    render(<EventsPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('You cannot view this media.');
    expect(screen.queryByTestId('player')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
    await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
    expect(window.location.search).toBe('');
  });

  it('shows access errors and no controls if initialization is denied', async () => {
    jest.mocked(fetchWrapper.get).mockRejectedValue('Event access is unavailable.');
    render(<EventsPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Event access is unavailable.');
    expect(screen.queryByRole('button', { name: 'Create event' })).toBeNull();
    expect(screen.queryByLabelText('Event')).toBeNull();
  });

  it('hides management controls from attendees and lists only available event choices', async () => {
    const implementation = jest.mocked(fetchWrapper.get).getMockImplementation()!;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => url === '/api/events/settings' ? { data: { ...settings, is_admin: false } } : url === '/api/events' ? { data: [{ ...event, can_manage: false }] } : implementation(url));
    render(<EventsPage />);
    await screen.findByText('Welcome video');
    expect(screen.queryByRole('button', { name: 'Create event' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Manage featured curators' })).toBeNull();
    expect(within(screen.getByLabelText('Event')).getAllByRole('option')).toHaveLength(2);
  });
  it('hides disabled Favorite and Library controls while retaining event browsing', async () => {
    jest.mocked(readInitialData).mockReturnValue({ instance: { modules: { favorites: false, library: false } } });
    render(<EventsPage />);
    await screen.findByText('Welcome video');
    expect(screen.queryByRole('link', { name: 'Sam’s favorites' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'My collection' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Manage featured curators' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Favorite' })).toBeNull();
    expect(screen.queryByLabelText('Favorites by')).toBeNull();
    fireEvent.click(screen.getByRole('link', { name: 'Open Welcome video' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByRole('button', { name: 'Save to Library' })).toBeNull();
    expect(within(dialog).queryByRole('button', { name: 'Favorite' })).toBeNull();
  });

  it('reveals filters on demand and clears attendee filters through URL navigation', async () => {
    render(<EventsPage />);
    await screen.findByText('Welcome video');
    expect(screen.getByRole('link', { name: 'Welcome' })).toBeInTheDocument();
    expect(screen.queryByLabelText('Uploaded by')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Filter media' }));
    expect(screen.getByLabelText('Uploaded by')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Uploaded by'), { target: { value: '7' } });
    fireEvent.click(screen.getByRole('button', { name: 'Filter media' }));
    expect(screen.queryByLabelText('Uploaded by')).not.toBeInTheDocument();
    expect(new URLSearchParams(window.location.search).get('attendee_id')).toBe('7');
    fireEvent.click(screen.getByRole('button', { name: 'Filter media' }));
    expect(screen.getByLabelText('Uploaded by')).toHaveValue('7');
    expect(await screen.findByRole('heading', { name: 'Uploads by Sam' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('link', { name: 'Clear filters' }));
    expect(window.location.search).not.toContain('attendee_id');
    expect(await screen.findByRole('heading', { name: 'All photos & videos' })).toBeInTheDocument();
  });

  it('uses one viewer history entry across items and returns to the gallery scroll position', async () => {
    const second = { ...media, id: 12, ulid: 'media-two', title: 'Second photo', type: 'photo', url: '/second' };
    const implementation = jest.mocked(fetchWrapper.get).getMockImplementation()!;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => url.startsWith('/api/events/media?') ? { data: [media, second], meta: { ...meta, last_page: 1, has_more: false, total: 2 } } : url === '/api/events/media/media-two' ? { data: second } : implementation(url));
    Object.defineProperty(window, 'scrollY', { configurable: true, value: 333 });
    render(<EventsPage />);
    const initialLength = window.history.length;
    fireEvent.click(await screen.findByRole('link', { name: 'Open Welcome video' }));
    await screen.findByRole('dialog');
    expect(window.history.length).toBe(initialLength + 1);
    fireEvent.click(screen.getByRole('button', { name: 'Next item' }));
    await screen.findByRole('heading', { name: 'Second photo' });
    expect(window.location.search).toContain('item=media-two');
    expect(window.history.length).toBe(initialLength + 1);
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(window.location.search).toBe('');
    await waitFor(() => expect(window.scrollTo).toHaveBeenCalledWith(0, 333));
  });

  it('closes a direct media link by replacing its URL without navigating away', async () => {
    window.history.replaceState({}, '', '/events?event_ulid=event-one&media=media-one');
    const back = jest.spyOn(window.history, 'back');
    render(<EventsPage />);
    await screen.findByRole('dialog');
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(window.location.search).toBe('?event_ulid=event-one');
    expect(back).not.toHaveBeenCalled();
    back.mockRestore();
  });

  it.each([
    { activation: 'pointer', detail: 1, restored: 546 },
    { activation: 'keyboard', detail: 0, restored: 224 },
  ])('restores the $activation activation position when native focus scrolls before click', async ({ detail, restored }) => {
    render(<EventsPage />);
    const link = await screen.findByRole('link', { name: 'Open Welcome video' });
    Object.defineProperty(window, 'scrollY', { configurable: true, value: 546 });
    fireEvent(link, new MouseEvent('pointerdown', { bubbles: true, button: 0 }));
    Object.defineProperty(window, 'scrollY', { configurable: true, value: 224 });
    fireEvent.click(link, { detail });
    await screen.findByRole('dialog');
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(window.scrollTo).toHaveBeenCalledWith(0, restored));
  });

  it('continues viewer navigation across gallery page boundaries with full detail loading', async () => {
    const second = { ...media, id: 12, ulid: 'media-two', title: 'Next page photo', type: 'photo', url: '/second' };
    const implementation = jest.mocked(fetchWrapper.get).getMockImplementation()!;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => url.startsWith('/api/events/media?page=2') ? { data: [second], meta: { ...meta, current_page: 2, has_more: false } } : url === '/api/events/media/media-two' ? { data: second } : implementation(url));
    render(<EventsPage />);
    fireEvent.click(await screen.findByRole('link', { name: 'Open Welcome video' }));
    await screen.findByRole('dialog');
    fireEvent.click(screen.getByRole('button', { name: 'Next item' }));
    expect(await screen.findByRole('heading', { name: 'Next page photo' })).toBeInTheDocument();
    expect(window.location.search).toContain('page=2');
    expect(fetchWrapper.get).toHaveBeenCalledWith('/api/events/media/media-two');
  });

  it('silently refreshes review-pending listings and stops once shared', async () => {
    jest.useFakeTimers();
    let pending = true;
    const implementation = jest.mocked(fetchWrapper.get).getMockImplementation()!;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => url.startsWith('/api/events/media?') ? { data: [{ ...media, type: 'photo', url: '/photo', under_review: pending }], meta } : implementation(url));
    const { unmount } = render(<EventsPage />);
    try {
      await act(async () => {});
      expect(screen.getByText(/Awaiting review/)).toBeInTheDocument();
      pending = false;
      await act(async () => { jest.advanceTimersByTime(5_000); });
      expect(screen.getByText(/Sam · Welcome · Shared/)).toBeInTheDocument();
      const count = jest.mocked(fetchWrapper.get).mock.calls.filter(([url]) => url.startsWith('/api/events/media?')).length;
      await act(async () => { jest.advanceTimersByTime(60_000); });
      expect(jest.mocked(fetchWrapper.get).mock.calls.filter(([url]) => url.startsWith('/api/events/media?'))).toHaveLength(count);
    } finally { unmount(); jest.useRealTimers(); }
  });

  it('refreshes active review/video transitions and stops on readiness or revocation', async () => {
    jest.useFakeTimers();
    window.history.replaceState({}, '', '/events?media=media-one');
    let stage = 0;
    const implementation = jest.mocked(fetchWrapper.get).getMockImplementation()!;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => {
      if (url !== '/api/events/media/media-one') return implementation(url);
      if (stage === 3) throw new Error('Access removed.');
      return { data: { ...media, under_review: stage === 0, video: { status: stage === 2 ? 'ready' : 'processing', master_url: stage === 2 ? '/ready.m3u8' : null } } };
    });
    const { unmount } = render(<EventsPage />);
    try {
      await act(async () => {});
      expect(screen.getByText(/Sam · Welcome · Awaiting review/)).toBeInTheDocument();
      stage = 1;
      await act(async () => { jest.advanceTimersByTime(5_000); });
      expect(screen.getByText(/Sam · Welcome · Preparing video/)).toBeInTheDocument();
      stage = 2;
      await act(async () => { jest.advanceTimersByTime(5_000); });
      expect(screen.getByTestId('player')).toHaveTextContent('/ready.m3u8');
      await act(async () => { jest.advanceTimersByTime(60_000); });
      expect(jest.mocked(fetchWrapper.get).mock.calls.filter(([url]) => url === '/api/events/media/media-one')).toHaveLength(3);
    } finally { unmount(); jest.useRealTimers(); }
  });

  it('bounds active polling, provides manual refresh, and cleans up when closed', async () => {
    jest.useFakeTimers();
    window.history.replaceState({}, '', '/events?media=media-one');
    const implementation = jest.mocked(fetchWrapper.get).getMockImplementation()!;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => url === '/api/events/media/media-one' ? { data: { ...media, under_review: true } } : implementation(url));
    const { unmount } = render(<EventsPage />);
    try {
      await act(async () => {});
      for (let attempt = 0; attempt < EVENT_REFRESH_LIMIT; attempt += 1) await act(async () => { jest.advanceTimersByTime(eventRefreshDelay(attempt)); });
      expect(screen.getByText('Automatic updates have paused.')).toBeInTheDocument();
      expect(jest.mocked(fetchWrapper.get).mock.calls.filter(([url]) => url === '/api/events/media/media-one')).toHaveLength(EVENT_REFRESH_LIMIT + 1);
      await act(async () => { jest.advanceTimersByTime(600_000); });
      expect(jest.mocked(fetchWrapper.get).mock.calls.filter(([url]) => url === '/api/events/media/media-one')).toHaveLength(EVENT_REFRESH_LIMIT + 1);
      fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Check for updates' }));
      await act(async () => {});
      fireEvent.click(screen.getByRole('button', { name: 'Close' }));
      await act(async () => { jest.advanceTimersByTime(60_000); });
      expect(jest.mocked(fetchWrapper.get).mock.calls.filter(([url]) => url === '/api/events/media/media-one')).toHaveLength(EVENT_REFRESH_LIMIT + 2);
    } finally { unmount(); jest.useRealTimers(); }
  });

  it('clears revoked detail playback and stops polling rather than retaining a stale item', async () => {
    jest.useFakeTimers();
    window.history.replaceState({}, '', '/events?media=media-one');
    let revoked = false;
    const implementation = jest.mocked(fetchWrapper.get).getMockImplementation()!;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => {
      if (url !== '/api/events/media/media-one') return implementation(url);
      if (revoked) throw new Error('Access removed.');
      return { data: { ...media, under_review: true } };
    });
    const { unmount } = render(<EventsPage />);
    try {
      await act(async () => {});
      revoked = true;
      await act(async () => { jest.advanceTimersByTime(5_000); });
      expect(screen.queryByTestId('player')).toBeNull();
      expect(screen.getByRole('alert')).toHaveTextContent('Access removed.');
      await act(async () => { jest.advanceTimersByTime(60_000); });
      expect(jest.mocked(fetchWrapper.get).mock.calls.filter(([url]) => url === '/api/events/media/media-one')).toHaveLength(2);
    } finally { unmount(); jest.useRealTimers(); }
  });

  it('pauses automatic requests while the tab is hidden', async () => {
    jest.useFakeTimers();
    window.history.replaceState({}, '', '/events?media=media-one');
    const implementation = jest.mocked(fetchWrapper.get).getMockImplementation()!;
    jest.mocked(fetchWrapper.get).mockImplementation(async (url) => url === '/api/events/media/media-one' ? { data: { ...media, under_review: true } } : implementation(url));
    const { unmount } = render(<EventsPage />);
    try {
      await act(async () => {});
      Object.defineProperty(document, 'hidden', { configurable: true, value: true });
      await act(async () => { jest.advanceTimersByTime(5_000); });
      expect(jest.mocked(fetchWrapper.get).mock.calls.filter(([url]) => url === '/api/events/media/media-one')).toHaveLength(1);
      Object.defineProperty(document, 'hidden', { configurable: true, value: false });
      await act(async () => { jest.advanceTimersByTime(5_000); });
      expect(jest.mocked(fetchWrapper.get).mock.calls.filter(([url]) => url === '/api/events/media/media-one')).toHaveLength(2);
    } finally { unmount(); Reflect.deleteProperty(document, 'hidden'); jest.useRealTimers(); }
  });

  it('provides a selectable joining URL when clipboard access fails', async () => {
    window.history.replaceState({}, '', '/events?event_ulid=event-one');
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: jest.fn().mockRejectedValue(new Error('Denied')) } });
    render(<EventsPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Copy joining link' }));
    expect(await screen.findByLabelText('Joining link')).toHaveValue(`${window.location.origin}/events/join/event-one`);
    expect(screen.getByLabelText('Joining link')).toHaveAttribute('readonly');
  });

});
