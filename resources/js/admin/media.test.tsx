import { render, screen } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { AdminMediaPage } from './media';

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn(),
    post: jest.fn(),
  },
}));

it('warns when duplicate summaries come from a truncated scan', async () => {
  jest.mocked(fetchWrapper.get).mockResolvedValue({
    success: true,
    data: [],
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 24,
      total: 0,
      has_more: false,
    },
    duplicate_scan: {
      truncated: true,
      scanned_media_count: 500,
      scan_limit: 500,
    },
  });

  render(<AdminMediaPage />);

  expect(await screen.findByRole('alert')).toHaveTextContent(
    'Duplicate signals cover only the newest 500 eligible photos',
  );
});
