import { fireEvent, render, screen } from '@testing-library/react';

import { OwnerMediaManager } from '@/media/OwnerMediaManager';
import type { MediaItem } from '@/media/types';
import { useMediaListing } from '@/media/useMediaListing';

jest.mock('@/components/protected-image', () => ({
  ProtectedImage: ({ alt }: { alt: string }) => <img alt={alt} />,
}));
jest.mock('@/media/MediaUploadDialog', () => ({
  MediaUploadDialog: () => <button type="button">Upload media</button>,
}));
jest.mock('@/media/useMediaListing', () => ({
  useMediaListing: jest.fn(),
}));

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
  url: '/media.jpg',
  thumbnail_url: null,
  video: null,
  interests: [],
  character: null,
  created_at: null,
};

beforeEach(() => {
  jest.mocked(useMediaListing).mockReturnValue({
    items: [item],
    loading: false,
    loadingMore: false,
    error: null,
    hasMore: false,
    reload: jest.fn(),
    loadMore: jest.fn(),
    removeLocal: jest.fn(),
  });
});

it('keeps bulk management while removing single-item Edit and Delete from grid tiles', () => {
  render(
    <OwnerMediaManager
      userId={3}
      identity={null}
      characters={[]}
      lastInterestIds={[]}
    />,
  );

  expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull();
  expect(screen.getByRole('link', { name: 'Open Gallery item' })).toHaveAttribute('href', '/m/01HZX5MEDIA');

  fireEvent.click(screen.getByRole('button', { name: 'Select visible' }));
  const editSelected = screen.getByRole('button', { name: 'Edit selected' });
  expect(editSelected).toBeEnabled();
  fireEvent.click(editSelected);

  expect(screen.getByRole('dialog', { name: 'Edit 1 selected' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Delete selected' })).toBeInTheDocument();
});
