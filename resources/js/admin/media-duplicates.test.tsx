import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { AdminMediaDuplicatesPage } from './media-duplicates';

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn(),
    post: jest.fn(),
  },
}));

const clusterResponse = {
  success: true,
  meta: {
    duplicate_scan: {
      truncated: false,
      scanned_media_count: 2,
      scan_limit: 500,
    },
  },
  data: [
    {
      id: 'cluster-12',
      media_count: 2,
      account_count: 2,
      newest_at: '2026-07-29T00:00:00Z',
      media: [
        {
          id: 12,
          ulid: '01FIRST',
          type: 'photo',
          title: 'First image',
          original_filename: 'first.jpg',
          moderation_status: 'pending',
          url: 'https://storage.example/first',
          thumbnail_url: null,
          user: {
            id: 21,
            name: 'First account',
            email: 'first@example.test',
          },
        },
        {
          id: 13,
          ulid: '01SECOND',
          type: 'photo',
          title: 'Second image',
          original_filename: 'second.jpg',
          moderation_status: 'pending',
          url: 'https://storage.example/second',
          thumbnail_url: null,
          user: {
            id: 22,
            name: 'Second account',
            email: 'second@example.test',
          },
        },
      ],
    },
  ],
};

beforeEach(() => {
  jest.clearAllMocks();
  jest.mocked(fetchWrapper.get).mockResolvedValue(clusterResponse);
  jest.mocked(fetchWrapper.post).mockResolvedValue({ success: true });
});

it('shows cross-account clusters with item and account review links', async () => {
  render(<AdminMediaDuplicatesPage />);

  expect(await screen.findByText('2 media across 2 accounts')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'First image' })).toHaveAttribute('href', '/m/01FIRST');
  expect(screen.getByRole('link', { name: /First account/ })).toHaveAttribute(
    'href',
    '/admin/users#user-21',
  );
});

it('reloads clusters with the selected sort order', async () => {
  render(<AdminMediaDuplicatesPage />);
  await screen.findByText('2 media across 2 accounts');

  fireEvent.click(screen.getByRole('button', { name: 'Newest activity' }));

  await waitFor(() =>
    expect(fetchWrapper.get).toHaveBeenCalledWith('/api/admin/media-duplicates?sort=newest_desc'),
  );
});

it('warns when the bounded scan omits older eligible media', async () => {
  jest.mocked(fetchWrapper.get).mockResolvedValue({
    ...clusterResponse,
    meta: {
      duplicate_scan: {
        truncated: true,
        scanned_media_count: 500,
        scan_limit: 500,
      },
    },
  });

  render(<AdminMediaDuplicatesPage />);

  expect(await screen.findByRole('alert')).toHaveTextContent(
    'Only the newest 500 eligible photos were scanned',
  );
});

it('queues suspicious media into the existing abuse-review flow', async () => {
  render(<AdminMediaDuplicatesPage />);
  await screen.findByText('2 media across 2 accounts');

  fireEvent.click(screen.getAllByRole('button', { name: 'Send to abuse review' })[0]!);

  await waitFor(() =>
    expect(fetchWrapper.post).toHaveBeenCalledWith('/api/admin/media/12/duplicate-review', {}),
  );
  expect(screen.getByRole('link', { name: 'Open abuse reports' })).toHaveAttribute(
    'href',
    '/admin/reports',
  );
});
