import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';

import { SaveToLibraryButton } from './SaveToLibraryButton';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn() } }));
jest.mock('sonner', () => ({ toast: { success: jest.fn() } }));

const get = jest.mocked(fetchWrapper.get);
const post = jest.mocked(fetchWrapper.post);

describe('SaveToLibraryButton', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    get.mockResolvedValue({ data: [
      { ulid: 'mine', name: 'My collection', owned: true, member_ids: [] },
      { ulid: 'theirs', name: 'Someone else’s collection', owned: false, member_ids: [] },
    ] });
    post.mockResolvedValue({ data: { ulid: 'saved-item' } });
  });

  it('explains retention and saves to an owned collection without changing a favorite', async () => {
    render(<SaveToLibraryButton mediaUlid="source-media" />);
    fireEvent.click(screen.getByRole('button', { name: 'Save to Library' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText(/Keep access here even if the original uploader/)).toBeInTheDocument();
    await screen.findByRole('option', { name: 'My collection' });
    expect(screen.queryByRole('option', { name: 'Someone else’s collection' })).toBeNull();
    fireEvent.change(within(dialog).getByLabelText('Collection (optional)'), { target: { value: 'mine' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save to Library' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/library/save', { media_ulid: 'source-media', collection_ulid: 'mine' }));
    expect(await screen.findByRole('link', { name: 'Open in Library' })).toHaveAttribute('href', '/library?item=saved-item');
  });

  it('retains the dialog and selected collection after a failed save so it can be retried', async () => {
    post.mockRejectedValueOnce('Library storage is not enabled.');
    render(<SaveToLibraryButton itemUlid="shared-item" />);
    fireEvent.click(screen.getByRole('button', { name: 'Save to Library' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save to Library' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Library storage is not enabled.');
    expect(screen.queryByRole('link', { name: 'Open in Library' })).toBeNull();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save to Library' }));
    await waitFor(() => expect(post).toHaveBeenLastCalledWith('/api/library/save', { item_ulid: 'shared-item', collection_ulid: null }));
    expect(await screen.findByRole('link', { name: 'Open in Library' })).toBeInTheDocument();
  });
});
