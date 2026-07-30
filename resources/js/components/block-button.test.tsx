import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { BlockButton } from './block-button';

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    delete: jest.fn(),
    post: jest.fn(),
  },
}));

jest.mock('sonner', () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const post = jest.mocked(fetchWrapper.post);
const remove = jest.mocked(fetchWrapper.delete);

describe('BlockButton', () => {
  beforeEach(() => {
    post.mockReset();
    remove.mockReset();
    post.mockImplementation(async (url) => (
      url === '/api/reports'
        ? { message: 'Report submitted.' }
        : { data: { block_id: 42 } }
    ));
    remove.mockResolvedValue({ data: { blocked: false } });
  });

  it('confirms an exact persona block without describing other identities', async () => {
    render(<BlockButton type="character" id={9} displayName="Kira" />);

    fireEvent.click(screen.getByRole('button', { name: 'Open block confirmation for Kira' }));

    const dialog = await screen.findByRole('alertdialog');
    expect(dialog).toHaveTextContent(
      "Kira won't be able to see your profile or posts, or interact with you.",
    );
    expect(dialog).toHaveTextContent(
      "You won't see Kira in your feed or search. Kira isn't notified, but will be able to tell.",
    );
    expect(dialog).not.toHaveTextContent(/linked|connected|other identities|owner|account-wide/i);
    expect(screen.getByRole('checkbox', { name: 'Also report Kira to the moderation team' })).not.toBeChecked();

    fireEvent.click(screen.getByRole('button', { name: 'Block Kira' }));

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/characters/9/block', {}));
    expect(post).toHaveBeenCalledTimes(1);
    expect(await screen.findByRole('button', { name: 'Open unblock confirmation for Kira' })).toBeInTheDocument();
  });

  it('warns that follows are not restored before unblocking', async () => {
    render(<BlockButton type="user" id={7} displayName="Ben" initialBlockId={42} />);

    fireEvent.click(screen.getByRole('button', { name: 'Open unblock confirmation for Ben' }));

    expect(await screen.findByRole('alertdialog')).toHaveTextContent(
      'Follow relationships removed when you blocked Ben will not be restored.',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Unblock Ben' }));

    await waitFor(() => expect(remove).toHaveBeenCalledWith('/api/blocks/42'));
    expect(await screen.findByRole('button', { name: 'Open block confirmation for Ben' })).toBeInTheDocument();
  });

  it('attempts an opted-in report before blocking and still blocks if reporting fails', async () => {
    let rejectReport: ((reason?: unknown) => void) | undefined;
    const reportAttempt = new Promise((_, reject) => {
      rejectReport = reject;
    });
    post.mockImplementation((url) => (
      url === '/api/reports'
        ? reportAttempt
        : Promise.resolve({ data: { block_id: 42 } })
    ));
    render(<BlockButton type="character" id={9} displayName="Kira" />);

    fireEvent.click(screen.getByRole('button', { name: 'Open block confirmation for Kira' }));
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Also report Kira to the moderation team' }));
    fireEvent.click(screen.getByRole('button', { name: 'Block Kira' }));

    expect(await screen.findByRole('dialog')).toHaveTextContent('Report this content');
    fireEvent.change(screen.getByRole('combobox', { name: 'Reason' }), { target: { value: 'harassment' } });
    fireEvent.click(screen.getByRole('button', { name: 'Submit report' }));

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/reports', {
      type: 'character',
      id: 9,
      reason: 'harassment',
      details: null,
    }));
    expect(post).toHaveBeenCalledTimes(1);

    rejectReport?.('Report unavailable.');

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/characters/9/block', {}));
    expect(post.mock.calls.map(([url]) => url)).toEqual([
      '/api/reports',
      '/api/characters/9/block',
    ]);
  });

  it('blocks after an opted-in report is cancelled', async () => {
    render(<BlockButton type="user" id={7} displayName="Ben" />);

    fireEvent.click(screen.getByRole('button', { name: 'Open block confirmation for Ben' }));
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Also report Ben to the moderation team' }));
    fireEvent.click(screen.getByRole('button', { name: 'Block Ben' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel' }));

    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/users/7/block', {}));
    expect(post).toHaveBeenCalledTimes(1);
  });
});
