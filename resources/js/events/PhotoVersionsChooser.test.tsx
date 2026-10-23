import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { PhotoDetails, PhotoVersionsChooser } from '@/events/PhotoVersionsChooser';
import type { EventMediaItem } from '@/events/types';
import { fetchWrapper } from '@/fetchWrapper';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn() } }));

const item: EventMediaItem = {
  id: 1, ulid: 'heic-original', character_id: null, type: 'photo', purpose: 'event', title: 'A moment',
  mime_type: 'image/heic', size_bytes: 1024, audience: 'specific', discoverable: false,
  upload_status: 'ready', url: '/display', thumbnail_url: '/thumb', video: null, interests: [], character: null,
  created_at: null, event: { ulid: 'event', name: 'Welcome' }, uploader: { id: 3, display_name: 'Alex' },
  owned: false, download_url: '/download-original', favorited: false,
  photo_versions: { visible_count: 2, preferred_ulid: 'jpeg-original', is_preferred: false },
  photo_details: { width: 2048, height: 1536, metadata_known: false, has_gps: false, has_capture_time: false, has_camera: false },
};
const preferred: EventMediaItem = {
  ...item, id: 2, ulid: 'jpeg-original', mime_type: 'image/jpeg', uploader: { id: 7, display_name: 'Sam' },
  photo_versions: { visible_count: 2, preferred_ulid: 'jpeg-original', is_preferred: true },
  photo_details: { width: 4000, height: 3000, metadata_known: true, has_gps: true, has_capture_time: true, has_camera: true },
};
const meta = { current_page: 1, last_page: 1, per_page: 24, total: 2, has_more: false };
const response = { data: [preferred, item], meta, preferred_ulid: preferred.ulid };

describe('Event photo versions', () => {
  beforeEach(() => { jest.resetAllMocks(); jest.mocked(fetchWrapper.get).mockResolvedValue(response); });

  it('loads versions on demand, identifies the current original, and chooses a concrete version', async () => {
    const choose = jest.fn();
    render(<PhotoVersionsChooser item={item} onChoose={choose} />);
    expect(fetchWrapper.get).not.toHaveBeenCalled();
    const toggle = screen.getByRole('button', { name: 'Show versions' });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(toggle);
    const preferredButton = await screen.findByRole('button', { name: 'View version 1 · JPEG · Sam' });
    expect(fetchWrapper.get).toHaveBeenCalledWith('/api/events/media/heic-original/variants?page=1');
    expect(within(preferredButton).getByText('Preferred version')).toBeInTheDocument();
    expect(within(preferredButton).getByText(/4000 × 3000 pixels/)).toBeInTheDocument();
    expect(within(preferredButton).getByText('Capture time · Camera details · Location metadata')).toBeInTheDocument();
    const current = screen.getByRole('button', { name: 'View version 2 · HEIC · Alex' });
    expect(current).toBeDisabled();
    expect(current).toHaveAttribute('aria-current', 'true');
    expect(within(current).getByText('Metadata not inspected')).toBeInTheDocument();
    fireEvent.click(preferredButton);
    expect(choose).toHaveBeenCalledWith('jpeg-original');
    fireEvent.click(screen.getByRole('button', { name: 'Hide versions' }));
    expect(screen.queryByRole('list')).toBeNull();
  });

  it('paginates without retaining earlier result pages and provides previous navigation', async () => {
    jest.mocked(fetchWrapper.get).mockResolvedValueOnce({ ...response, meta: { ...meta, last_page: 2, has_more: true, total: 25 } })
      .mockResolvedValueOnce({ data: [{ ...preferred, ulid: 'last-original' }], preferred_ulid: preferred.ulid, meta: { ...meta, current_page: 2, last_page: 2, total: 25 } });
    render(<PhotoVersionsChooser item={item} onChoose={jest.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Show versions' }));
    fireEvent.click(await screen.findByRole('button', { name: 'More versions' }));
    expect(await screen.findByRole('button', { name: 'View version 25 · JPEG · Sam' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'View version 1 · JPEG · Sam' })).toBeNull();
    expect(screen.getByRole('button', { name: 'More versions' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Previous versions' }));
    await waitFor(() => expect(fetchWrapper.get).toHaveBeenLastCalledWith('/api/events/media/heic-original/variants?page=1'));
  });

  it('keeps errors recoverable without exposing stale version actions', async () => {
    jest.mocked(fetchWrapper.get).mockRejectedValueOnce('These versions are no longer available.');
    render(<PhotoVersionsChooser item={item} onChoose={jest.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Show versions' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('These versions are no longer available.');
    expect(screen.queryByRole('list')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Try versions again' }));
    expect(await screen.findByRole('button', { name: 'View version 1 · JPEG · Sam' })).toBeInTheDocument();
  });

  it('ignores a response after the chooser closes', async () => {
    let resolve!: (value: typeof response) => void;
    jest.mocked(fetchWrapper.get).mockReturnValue(new Promise((done) => { resolve = done; }));
    render(<PhotoVersionsChooser item={item} onChoose={jest.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Show versions' }));
    expect(screen.getByRole('status')).toHaveTextContent('Loading versions');
    fireEvent.click(screen.getByRole('button', { name: 'Hide versions' }));
    await act(async () => { resolve(response); });
    expect(screen.queryByRole('list')).toBeNull();
  });

  it('does not offer grouping for independent or pending photos', () => {
    render(<PhotoVersionsChooser item={{ ...item, photo_versions: null, under_review: true }} onChoose={jest.fn()} />);
    expect(screen.queryByLabelText('Photo versions')).toBeNull();
    expect(fetchWrapper.get).not.toHaveBeenCalled();
  });

  it('disables version selection while the concrete item is changing', async () => {
    const { rerender } = render(<PhotoVersionsChooser item={item} onChoose={jest.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Show versions' }));
    await screen.findByRole('button', { name: 'View version 1 · JPEG · Sam' });
    rerender(<PhotoVersionsChooser item={item} onChoose={jest.fn()} disabled />);
    expect(screen.getByRole('button', { name: 'View version 1 · JPEG · Sam' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Hide versions' })).toBeDisabled();
  });

  it('describes known metadata absence without rendering raw EXIF fields', () => {
    render(<PhotoDetails item={{ ...item, photo_details: { width: null, height: null, metadata_known: true, has_gps: false, has_capture_time: false, has_camera: false } }} />);
    expect(screen.getByText('No capture, camera or location metadata detected')).toBeInTheDocument();
    expect(screen.getByText('HEIC · 1.0 KB')).toBeInTheDocument();
  });
});
