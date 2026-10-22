import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { EventsPage } from '@/events/page';
import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn(), patch: jest.fn(), put: jest.fn(), delete: jest.fn() } }));
jest.mock('@/initialData', () => ({ readInitialData: jest.fn() }));
jest.mock('sonner', () => ({ Toaster: () => null }));
jest.mock('@/components/favorite-button', () => ({ FavoriteButton: () => <button>Favorite</button> }));
jest.mock('@/library/SaveToLibraryButton', () => ({ SaveToLibraryButton: () => <button>Save to Library</button> }));
jest.mock('@/media/MediaPlayer', () => ({ MediaPlayer: ({ item }: { item: { video: { master_url: string } } }) => <div data-testid="player">{item.video?.master_url}</div> }));
jest.mock('@/media/MediaUploadDialog', () => ({ MediaUploadDialog: ({ eventUlid, triggerLabel }: { eventUlid: string; triggerLabel: string }) => <button data-event={eventUlid}>{triggerLabel}</button> }));

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
  beforeEach(() => { jest.resetAllMocks(); jest.mocked(readInitialData).mockReturnValue({}); window.history.replaceState({}, '', '/events'); mockRequests(); });

  it('loads visible events and requires an explicit event before upload', async () => {
    render(<EventsPage />);
    expect(screen.getByRole('status')).toHaveTextContent('Loading events');
    expect(await screen.findByText('Welcome video')).toBeInTheDocument();
    expect(screen.getByText(/Choose an event above to upload/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Upload to Welcome' })).toBeNull();
    fireEvent.change(screen.getByLabelText('Event'), { target: { value: 'event-one' } });
    expect(await screen.findByRole('button', { name: 'Upload to Welcome' })).toHaveAttribute('data-event', 'event-one');
    expect(screen.getByText('All attendees', { selector: '[data-slot=badge]' })).toBeInTheDocument();
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith('/api/events/media?page=1&event_ulid=event-one'));
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
    fireEvent.click(screen.getByRole('link', { name: 'Featured favorites' }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenCalledWith('/api/events/media?page=1&event_ulid=event-one&featured=1'));
    expect(await screen.findByText(/Favorites selected by Sam/)).toBeInTheDocument();
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
    expect(within(dialog).getByRole('link', { name: 'Download to device' })).toHaveAttribute('href', '/api/events/media/media-one/download');
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
    expect(screen.queryByRole('link', { name: 'Featured favorites' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'My collection' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Manage featured curators' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Favorite' })).toBeNull();
    expect(screen.queryByLabelText('Favorites by')).toBeNull();
    fireEvent.click(screen.getByRole('link', { name: 'Open Welcome video' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByRole('button', { name: 'Save to Library' })).toBeNull();
    expect(within(dialog).queryByRole('button', { name: 'Favorite' })).toBeNull();
  });

});
