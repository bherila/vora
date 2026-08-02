import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { ActivityPage } from './activity';

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn(),
    delete: jest.fn(),
  },
}));
jest.mock('sonner', () => ({
  toast: { success: jest.fn(), error: jest.fn() },
  Toaster: () => null,
}));

const get = fetchWrapper.get as jest.Mock;
const remove = fetchWrapper.delete as jest.Mock;

describe('ActivityPage', () => {
  beforeEach(() => {
    get.mockReset();
    remove.mockReset();
    get.mockImplementation((url: string) => Promise.resolve({
      data: url.endsWith('type=comments') ? [{
        ulid: '01COMMENT',
        type: 'comment',
        body: 'My retained comment',
        status: 'removed_by_post_owner',
        created_at: null,
        parent: null,
        parent_unavailable: true,
      }] : [{
        ulid: '01POST',
        type: 'post',
        body: null,
        status: 'active',
        created_at: null,
        parent: { ulid: '01POST' },
      }],
    }));
    remove.mockResolvedValue({ success: true });
  });

  it('shows neutral unavailable-parent copy and deletes by contribution ULID', async () => {
    render(<ActivityPage />);
    await waitFor(() => expect(get).toHaveBeenCalledWith('/api/me/activity?type=posts'));
    expect(await screen.findByText('Post without text.')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Comments' }));

    expect(await screen.findByText('My retained comment')).toBeInTheDocument();
    expect(screen.getByText('Original post unavailable')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'View original post' })).not.toBeInTheDocument();
    expect(screen.getByText('Removed by the post owner')).toBeInTheDocument();

    fireEvent.click(screen.getByTitle('Delete contribution'));

    await waitFor(() => expect(remove).toHaveBeenCalledWith('/api/me/activity/comments/01COMMENT'));
    await waitFor(() => expect(screen.queryByText('My retained comment')).not.toBeInTheDocument());
  });
});
