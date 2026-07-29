import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import type { MediaItem } from '@/media/types';

import { MediaViewPage } from './view';

const mockReadInitialData = jest.fn();

jest.mock('@/initialData', () => ({
  readInitialData: () => mockReadInitialData(),
}));
jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    patch: jest.fn(),
    delete: jest.fn(),
  },
}));
jest.mock('@/media/MediaPlayer', () => ({
  MediaPlayer: ({ item }: { item: MediaItem }) => <div>Player for {item.title}</div>,
}));
jest.mock('@/components/favorite-button', () => ({ FavoriteButton: () => null }));
jest.mock('@/components/report-button', () => ({ ReportButton: () => null }));
jest.mock('sonner', () => ({
  Toaster: () => null,
  toast: {
    success: jest.fn(),
    error: jest.fn(),
  },
}));

function mediaItem(editable: boolean): MediaItem {
  const item: MediaItem = {
    id: 7,
    ulid: '01HZX5MEDIA',
    character_id: null,
    type: 'photo',
    purpose: 'gallery',
    title: 'Gallery item',
    mime_type: 'image/jpeg',
    size_bytes: 100,
    audience: 'everyone',
    discoverable: true,
    upload_status: 'ready',
    under_review: false,
    url: '/media.jpg',
    thumbnail_url: null,
    video: null,
    interests: [],
    character: null,
    created_at: null,
    owner: {
      id: editable ? 3 : 8,
      display_name: editable ? 'Owner' : 'Uploader',
      avatar_url: null,
      href: editable ? '/me' : '/users/8',
      is_self: editable,
    },
    can_report: !editable,
  };

  if (editable) {
    item.original_filename = 'owner-file.jpg';
    item.editable = {
      title: 'Gallery item',
      character_id: null,
      audience: 'everyone',
      audience_user_ids: [],
      discoverable: true,
      characters: [{ id: 12, display_name: 'Kira' }],
    };
  }

  return item;
}

beforeEach(() => {
  jest.clearAllMocks();
});

it('does not render owner controls when the visitor payload omits editable data', () => {
  mockReadInitialData.mockReturnValue({ mediaView: mediaItem(false) });

  render(<MediaViewPage />);

  expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull();
});

it('offers the owner the complete single-item edit form and saves through the existing endpoint', async () => {
  const item = mediaItem(true);
  mockReadInitialData.mockReturnValue({ mediaView: item });
  jest.mocked(fetchWrapper.patch).mockResolvedValue({
    success: true,
    data: { ...item, title: 'Updated title' },
  });

  render(<MediaViewPage />);

  fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
  const dialog = screen.getByRole('dialog', { name: 'Edit media' });
  expect(within(dialog).getByLabelText('Title')).toHaveValue('Gallery item');
  expect(within(dialog).getByLabelText('Character')).toHaveValue('');
  expect(within(dialog).getByLabelText('Who can see this?')).toHaveValue('everyone');
  expect(within(dialog).getByRole('checkbox', { name: /List in discovery/ })).toBeChecked();

  fireEvent.change(within(dialog).getByLabelText('Title'), { target: { value: 'Updated title' } });
  fireEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }));

  await waitFor(() => expect(fetchWrapper.patch).toHaveBeenCalledWith('/api/media/7', {
    title: 'Updated title',
    audience: 'everyone',
    audience_user_ids: [],
    discoverable: true,
  }));
  expect(await screen.findByRole('heading', { name: 'Updated title' })).toBeInTheDocument();
});

it('uses an alert dialog with the existing recovery wording before deleting', async () => {
  mockReadInitialData.mockReturnValue({ mediaView: mediaItem(true) });
  jest.mocked(fetchWrapper.delete).mockResolvedValue({ success: true });

  render(<MediaViewPage />);

  fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
  const alert = screen.getByRole('alertdialog', { name: 'Delete this item?' });
  expect(within(alert).getByText('It will be hidden from your profile and retained for admin recovery.')).toBeInTheDocument();
  fireEvent.click(within(alert).getByRole('button', { name: 'Delete' }));

  await waitFor(() => expect(fetchWrapper.delete).toHaveBeenCalledWith('/api/media/7'));
  expect(await screen.findByText('Media deleted.')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Return to your profile' })).toHaveAttribute('href', '/me');
});
