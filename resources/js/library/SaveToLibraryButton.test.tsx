import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { instanceProfile } from '@/instance';

import { SaveToLibraryButton } from './SaveToLibraryButton';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), post: jest.fn() } }));
jest.mock('@/instance', () => ({ instanceProfile: jest.fn(() => 'vora') }));
jest.mock('sonner', () => ({ toast: { success: jest.fn() } }));

const get = jest.mocked(fetchWrapper.get);
const post = jest.mocked(fetchWrapper.post);

beforeEach(() => {
  jest.clearAllMocks();
  jest.mocked(instanceProfile).mockReturnValue('vora');
  get.mockResolvedValue({ data: [
    { ulid: 'mine', name: 'My collection', owned: true, member_ids: [] },
    { ulid: 'theirs', name: 'Someone else’s collection', owned: false, member_ids: [] },
  ] });
  post.mockResolvedValue({ data: { ulid: 'saved-item' } });
});

test('saves immediately without fetching collections or opening an organization dialog', async () => {
  const onSaved = jest.fn();
  render(<SaveToLibraryButton mediaUlid="source-media" onSaved={onSaved} />);
  fireEvent.click(screen.getByRole('button', { name: 'Save to Library' }));
  expect(await screen.findByRole('link', { name: 'Open in Library' })).toHaveAttribute('href', '/library?item=saved-item');
  expect(post).toHaveBeenCalledWith('/api/library/save', { media_ulid: 'source-media', collection_ulid: null });
  expect(get).not.toHaveBeenCalled();
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(onSaved).toHaveBeenCalledTimes(1);
});

test('organizes an already saved item using membership without requesting another Save', async () => {
  render(<SaveToLibraryButton mediaUlid="source-media" />);
  fireEvent.click(screen.getByRole('button', { name: 'Save to Library' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Organize' }));
  const dialog = screen.getByRole('dialog');
  expect(within(dialog).getByText(/Already saved privately/)).toBeInTheDocument();
  expect(within(dialog).getByText(/Items saved from someone else stay private to you, including in shared collections/)).toBeInTheDocument();
  await screen.findByRole('option', { name: 'My collection' });
  expect(screen.queryByRole('option', { name: 'Someone else’s collection' })).not.toBeInTheDocument();
  fireEvent.change(within(dialog).getByLabelText('Collection'), { target: { value: 'mine' } });
  fireEvent.click(within(dialog).getByRole('button', { name: 'Add to collection' }));
  await waitFor(() => expect(post).toHaveBeenLastCalledWith('/api/library/items/saved-item/collections', { collection_ulid: 'mine' }));
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  expect(post.mock.calls.filter(([url]) => url === '/api/library/save')).toHaveLength(1);
});

test('keeps a failed Save retryable and does not report it as saved', async () => {
  post.mockRejectedValueOnce('Your storage quota is full.');
  render(<SaveToLibraryButton itemUlid="shared-item" />);
  fireEvent.click(screen.getByRole('button', { name: 'Save to Library' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Your storage quota is full.');
  expect(screen.queryByRole('link', { name: 'Open in Library' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Save to Library' }));
  expect(await screen.findByRole('link', { name: 'Open in Library' })).toBeInTheDocument();
  expect(post).toHaveBeenLastCalledWith('/api/library/save', { item_ulid: 'shared-item', collection_ulid: null });
});

test('prevents duplicate Save requests while a click is pending', async () => {
  let finish!: (value: { data: { ulid: string } }) => void;
  post.mockReturnValueOnce(new Promise((resolve) => { finish = resolve; }));
  render(<SaveToLibraryButton mediaUlid="source-media" />);
  const button = screen.getByRole('button', { name: 'Save to Library' });
  fireEvent.click(button);
  fireEvent.click(button);
  expect(button).toBeDisabled();
  expect(post).toHaveBeenCalledTimes(1);
  await act(async () => { finish({ data: { ulid: 'saved-item' } }); });
  expect(screen.getByRole('link', { name: 'Open in Library' })).toBeInTheDocument();
});

test('uses consistent Event collection wording and does not re-save an existing reference', async () => {
  jest.mocked(instanceProfile).mockReturnValue('event');
  const { unmount } = render(<SaveToLibraryButton mediaUlid="source-media" />);
  fireEvent.click(screen.getByRole('button', { name: 'Save to my collection' }));
  expect(await screen.findByRole('link', { name: 'View in my collection' })).toBeInTheDocument();
  unmount();
  post.mockClear();
  render(<SaveToLibraryButton mediaUlid="source-media" savedUlid="retained-item" />);
  expect(screen.getByRole('link', { name: 'View in my collection' })).toHaveAttribute('href', '/library?item=retained-item');
  expect(screen.queryByRole('button', { name: 'Save to my collection' })).not.toBeInTheDocument();
  expect(post).not.toHaveBeenCalled();
});

test('keeps the saved reference when optional organization fails and can retry it', async () => {
  render(<SaveToLibraryButton savedUlid="retained-item" />);
  fireEvent.click(screen.getByRole('button', { name: 'Organize' }));
  await screen.findByRole('option', { name: 'My collection' });
  fireEvent.change(screen.getByLabelText('Collection'), { target: { value: 'mine' } });
  post.mockRejectedValueOnce('Collection unavailable.');
  fireEvent.click(screen.getByRole('button', { name: 'Add to collection' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Collection unavailable.');
  expect(screen.getByLabelText('Collection')).toHaveValue('mine');
  expect(screen.getByRole('link', { name: 'Open in Library', hidden: true })).toHaveAttribute('href', '/library?item=retained-item');
  fireEvent.click(screen.getByRole('button', { name: 'Add to collection' }));
  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  expect(post.mock.calls.every(([url]) => url === '/api/library/items/retained-item/collections')).toBe(true);
});

test('explains that organization can wait when no collections exist', async () => {
  get.mockResolvedValue({ data: [] });
  render(<SaveToLibraryButton savedUlid="retained-item" />);
  fireEvent.click(screen.getByRole('button', { name: 'Organize' }));
  expect(await screen.findByText(/You don’t have any collections yet/)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Add to collection' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Done' }));
  expect(screen.getByRole('link', { name: 'Open in Library' })).toBeInTheDocument();
  expect(post).not.toHaveBeenCalled();
});
