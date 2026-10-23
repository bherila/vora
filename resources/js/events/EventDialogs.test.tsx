import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { CuratorEditor, EventEditor, EventMediaDialog } from '@/events/EventDialogs';
import type { EventMediaItem, EventProfile } from '@/events/types';
import { fetchWrapper } from '@/fetchWrapper';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn(), patch: jest.fn(), put: jest.fn(), delete: jest.fn() } }));
jest.mock('@/media/MediaPlayer', () => ({ MediaPlayer: ({ item }: { item: { type: string } }) => item.type === 'video' ? <video controls aria-label="Playback controls" /> : <div>Media player</div> }));
jest.mock('@/components/favorite-button', () => ({ FavoriteButton: () => <button>Favorite</button> }));
jest.mock('@/library/SaveToLibraryButton', () => ({ SaveToLibraryButton: () => <button>Save to Library</button> }));

const attendees = [{ id: 3, display_name: 'Alex' }, { id: 7, display_name: 'Sam' }];
const event: EventProfile = { ulid: 'event-one', name: 'Welcome', description: 'Gathering', timezone: 'UTC', starts_at: '2026-12-01T14:00:00+00:00', ends_at: null, visibility: 'specific', can_manage: true, member_ids: [3] };
const item: EventMediaItem = { id: 12, ulid: 'media-one', character_id: null, type: 'photo', purpose: 'gallery', title: 'Gathering photo', mime_type: 'image/png', size_bytes: 5, audience: 'specific', discoverable: false, upload_status: 'ready', url: '/asset', thumbnail_url: null, video: null, interests: [], character: null, created_at: null, event: { ulid: event.ulid, name: event.name }, uploader: attendees[0]!, owned: true, download_url: '/api/events/media/media-one/download', favorited: false };

describe('Event administration and media actions', () => {
  beforeEach(() => { jest.resetAllMocks(); });

  it('creates selected-attendee events with local date inputs and an event timezone', async () => {
    jest.mocked(fetchWrapper.post).mockResolvedValue({ success: true });
    const saved = jest.fn();
    const close = jest.fn();
    render(<EventEditor event={null} attendees={attendees} onSaved={saved} onClose={close} />);
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Shower' } });
    fireEvent.change(screen.getByLabelText('Timezone'), { target: { value: 'Europe/London' } });
    fireEvent.change(screen.getByLabelText('Starts at (optional)'), { target: { value: '2026-12-01T14:00:00' } });
    fireEvent.change(screen.getByLabelText('Who can access this event?'), { target: { value: 'specific' } });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sam' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save event' }));
    await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/admin/events', { name: 'Shower', description: null, timezone: 'Europe/London', starts_at: new Date('2026-12-01T14:00:00').toISOString(), ends_at: null, visibility: 'specific', member_ids: [7] }));
    expect(saved).toHaveBeenCalledTimes(1);
    expect(close).toHaveBeenCalledTimes(1);
  });

  it('preserves a failed event edit and clears hidden selected attendees for all-attendee access', async () => {
    jest.mocked(fetchWrapper.patch).mockRejectedValue('Please correct the event.');
    const close = jest.fn();
    render(<EventEditor event={event} attendees={attendees} onSaved={jest.fn()} onClose={close} />);
    expect(screen.getByRole('checkbox', { name: 'Alex' })).toBeChecked();
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Updated welcome' } });
    fireEvent.change(screen.getByLabelText('Who can access this event?'), { target: { value: 'everyone' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save event' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Please correct the event.');
    expect(screen.getByLabelText('Name')).toHaveValue('Updated welcome');
    expect(fetchWrapper.patch).toHaveBeenCalledWith('/api/admin/events/event-one', expect.objectContaining({ visibility: 'everyone', member_ids: [] }));
    expect(close).not.toHaveBeenCalled();
  });

  it('updates featured curators without hardcoded identities', async () => {
    jest.mocked(fetchWrapper.put).mockResolvedValue({ success: true });
    const saved = jest.fn();
    render(<CuratorEditor settings={{ featured_curators: [attendees[0]!], viewer_id: 3, is_admin: true }} attendees={attendees} onSaved={saved} onClose={jest.fn()} />);
    fireEvent.click(screen.getByRole('checkbox', { name: 'Alex' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sam' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save curators' }));
    await waitFor(() => expect(fetchWrapper.put).toHaveBeenCalledWith('/api/admin/events/settings', { featured_curator_ids: [7] }));
    expect(saved).toHaveBeenCalledTimes(1);
  });

  it('requires explicit confirmation before deleting an owned upload', async () => {
    jest.mocked(fetchWrapper.delete).mockResolvedValue({ success: true });
    const deleted = jest.fn();
    const close = jest.fn();
    render(<EventMediaDialog item={item} onClose={close} onDeleted={deleted} />);
    fireEvent.click(screen.getByRole('button', { name: 'Delete my upload' }));
    expect(fetchWrapper.delete).not.toHaveBeenCalled();
    expect(screen.getByText(/Items already saved/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }));
    await waitFor(() => expect(fetchWrapper.delete).toHaveBeenCalledWith('/api/media/12'));
    expect(deleted).toHaveBeenCalledTimes(1);
    expect(close).toHaveBeenCalledTimes(1);
  });

  it('keeps delete failures visible and does not expose unsafe download URLs', async () => {
    jest.mocked(fetchWrapper.delete).mockRejectedValue('Unable to delete.');
    render(<EventMediaDialog item={{ ...item, download_url: 'https://outside.example/file' }} onClose={jest.fn()} onDeleted={jest.fn()} />);
    expect(screen.queryByRole('link', { name: 'Download' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Delete my upload' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Unable to delete.');
    expect(screen.getByRole('button', { name: 'Confirm delete' })).toBeEnabled();
  });
  it('preserves available selected attendees and explicitly removes unavailable members when saving', async () => {
    jest.mocked(fetchWrapper.patch).mockResolvedValue({ success: true });
    render(<EventEditor event={{ ...event, member_ids: [3, 99] }} attendees={attendees} onSaved={jest.fn()} onClose={jest.fn()} />);
    expect(screen.getByText('Unavailable attendees will be removed when you save.')).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: 'Alex' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Sam' })).not.toBeChecked();
    fireEvent.click(screen.getByRole('button', { name: 'Save event' }));
    await waitFor(() => expect(fetchWrapper.patch).toHaveBeenCalledWith('/api/admin/events/event-one', expect.objectContaining({ member_ids: [3] })));
  });

  it('navigates with buttons, arrow keys and horizontal photo swipes, preserving vertical gestures', () => {
    const navigate = jest.fn();
    render(<EventMediaDialog item={item} onClose={jest.fn()} onDeleted={jest.fn()} onNavigate={navigate} hasPrevious hasNext position="Item 2 of 3" />);
    fireEvent.click(screen.getByRole('button', { name: 'Next item' }));
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'ArrowLeft' });
    const surface = screen.getByTestId('event-media-surface');
    fireEvent.touchStart(surface, { touches: [{ clientX: 150, clientY: 20 }] });
    fireEvent.touchEnd(surface, { changedTouches: [{ clientX: 40, clientY: 30 }] });
    fireEvent.touchStart(surface, { touches: [{ clientX: 50, clientY: 20 }] });
    fireEvent.touchEnd(surface, { changedTouches: [{ clientX: 45, clientY: 170 }] });
    expect(navigate.mock.calls.map(([direction]) => direction)).toEqual([1, -1, 1]);
    expect(screen.getByText('Item 2 of 3')).toBeInTheDocument();
  });

  it('leaves interactive video keys and gestures alone and respects viewer boundaries', () => {
    const navigate = jest.fn();
    render(<EventMediaDialog item={{ ...item, type: 'video' }} onClose={jest.fn()} onDeleted={jest.fn()} onNavigate={navigate} hasNext />);
    const video = screen.getByLabelText('Playback controls');
    fireEvent.keyDown(video, { key: 'ArrowRight' });
    fireEvent.touchStart(video, { touches: [{ clientX: 150, clientY: 20 }] });
    fireEvent.touchEnd(video, { changedTouches: [{ clientX: 20, clientY: 20 }] });
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'ArrowLeft' });
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'ArrowRight', altKey: true });
    expect(navigate).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Previous item' })).toBeDisabled();
  });

  it('leaves arrow keys in the versions chooser alone instead of navigating the gallery', () => {
    const navigate = jest.fn();
    render(<EventMediaDialog item={{ ...item, photo_versions: { visible_count: 2, preferred_ulid: item.ulid, is_preferred: true } }} onClose={jest.fn()} onDeleted={jest.fn()} onNavigate={navigate} onChooseVersion={jest.fn()} hasNext />);
    fireEvent.keyDown(screen.getByRole('button', { name: 'Show versions' }), { key: 'ArrowRight' });
    expect(navigate).not.toHaveBeenCalled();
  });

});
